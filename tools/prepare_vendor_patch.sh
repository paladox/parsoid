#!/bin/bash

set -eu -o pipefail

if [ $# -lt 2 ]; then
	echo "USAGE: $0 <old-git-tag> <new-git-tag> [<git-sha-of-new-tag> <vendor-repo> <core-repo>]"
	echo "If git-sha is omitted, HEAD is used by default"
	echo "If repo args are omitted, MW_VENDOR_REPO and MW_CORE_REPO environment variables are used"
	echo "Ex: $0 v0.19.0-a6 v0.19.0-a7 HEAD ../repos/vendor ../core"
	echo "Ex: $0 v0.19.0-a6 v0.19.0-a7 HEAD"
	echo "Ex: $0 v0.19.0-a6 v0.19.0-a7"
	echo "You have to skip OR provide both repo values on the CLI"
	echo "A specific composer install can be passed via the MW_COMPOSER env variable."
	exit 1
fi

waitForConfirmation() {
	while true; do
		# Don't accept from a file
		read -r -n 1 -p "Enter y/Y to continue or n/N to exit: " confirm < /dev/tty
		echo
		if [ "$confirm" == "y" ] || [ "$confirm" == "Y" ]; then
			break
		fi
		if [ "$confirm" == "n" ] || [ "$confirm" == "N" ]; then
			exit 1
		fi
	done
}

pwd="$PWD"
newTagSha=$(git rev-list -n 1 "HEAD") # DEFAULT

if [ $# -gt 2 ]; then
	newTagSha=$3
	if [ $# -gt 3 ]; then
		vendorRepo="$4"
		coreRepo="$5"
	fi
fi

if [ "${vendorRepo:-foo}" == "foo" ]; then
	if [ "${MW_VENDOR_REPO:-foo}" == "foo" ]; then
		echo "Please provide vendor repo on CLI or set MW_VENDOR_REPO environment variable"
		exit 1
	fi
	vendorRepo="$MW_VENDOR_REPO"
fi
if [ ! -d "$vendorRepo" ]; then
	echo "Vendor repo $vendorRepo doesn't exist. Please verify and try again."
	exit 1
fi
if [ "${coreRepo:-foo}" == "foo" ]; then
	if [ "${MW_CORE_REPO:-foo}" == "foo" ]; then
		echo "Please provide core repo on CLI or set MW_CORE_REPO environment variable"
		exit 1
	fi
	coreRepo="$MW_CORE_REPO"
fi
if [ ! -d "$coreRepo" ]; then
	echo "Core repo $coreRepo doesn't exist. Please verify and try again."
	exit 1
fi

# Generate deploy log
bash ./tools/gen_deploy_log.sh "$1" "$newTagSha" > deploy.log.txt
cat deploy.log.txt
echo "-----------------------------------------------"
echo "^^^ These patches will be part of the new tag."
waitForConfirmation
echo

tagCount=$(git tag -l "$2" | wc -l | xargs)
if [ "$tagCount" != "0" ]; then
	existingTagSha=$(git rev-list -n 1 "$2")
	if [[ "$existingTagSha" != "$newTagSha"* ]]; then
		echo "Tag $2 already exists but does not point to $newTagSha."
		exit 1
	fi
else
	# Tag & push new version
	echo "Creating new tag $2"
	git tag "$2" "$newTagSha"
fi

echo "Ready to push tag $2 (commit $newTagSha) to origin"
waitForConfirmation
echo
git push origin "$2"
echo "Pushed new tag $2 to origin"
echo

# Identify fixed bugs
fixedbugs=$(git log "$1".."$2" | (grep -E "^\s*Bug:" || echo "") | sed 's/^\s*//g;' | sort | uniq)

# --- Prepare vendor patch ---
# Update composer.json
cd "$vendorRepo"

## checkout master branch and update
git checkout master
git pull origin master --rebase
vstring=$(echo "$2" | sed 's/v//g;')
sed -i '' "s/wikimedia\/parsoid.*/wikimedia\/parsoid\": \"$vstring\",/g;" composer.json

# Wait a bit for changes to propagate to packagist
sleep 2
echo "Ready to prepare vendor patch. Please verify that packagist has the new tag."
echo "Visit https://packagist.org/packages/wikimedia/parsoid to verify."
waitForConfirmation
echo

# update packages
# FIXME: Verify that composer is running the version from the README
# `composer --version === 2.6.4`
echo "Running composer update"
"${MW_COMPOSER:-composer}" update --no-dev
echo

# Generate commit
# FIXME: Use a branch instead of master?
echo "Preparing vendor patch"
git add -A wikimedia/parsoid composer.lock composer.json composer
git commit -m "Bump wikimedia/parsoid to $vstring

$fixedbugs"
changeid=$(git log -1 | grep "Change-Id" | sed 's/.*://g;')

# --- Prepare core patch that depends on the vendor patch ---
cd "$pwd" # $5 could be relative or absolute - so go back to original dir first
cd "$coreRepo"
## checkout master branch and update
git checkout master
git pull origin master --rebase

echo
echo "Bumping Parsoid version in core and preparing patch"

sed -i '' "s/wikimedia\/parsoid.*/wikimedia\/parsoid\": \"$vstring\",/g;" composer.json
git commit composer.json -m "Bump wikimedia/parsoid to $vstring

Depends-On: $changeid"
echo

# Add instructions
echo "------ Followup needed ------"
echo "* Please add contents of $pwd/deploy.log.txt to [[mw:Parsoid/Deployments]]"
echo "* Please verify new patch in core repo ($coreRepo) and upload to gerrit for review"
echo "* Please verify new patch in vendor repo ($vendorRepo) and upload to gerrit for review"
echo "* Please +2 the uploaded core patch to ensure that when the vendor patch is +2ed, they merge together"
