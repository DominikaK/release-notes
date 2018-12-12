# eZ Platform release notes maker

This script prepares release notes for releases of [eZ Platform and eZ Platform Enterprise Edition](ezplatform.com).

## Prerequisites

To use the script you need a GitHub access token with `repo` scope.

Get it from your GitHub account's Settings > Developer settings > Personal access tokens

Place the token in a file named `token.txt` in the script's folder.

## Usage

To get full release notes for a tag in a meta-repository, run:

`php release_notes.php <meta-repository-name> <tag>`

For example:

`php release_notes.php ezplatform v2.3.0`

The script will produce a file for each component repository we cover in release notes,
as well as a separate file for the meta-repository.

### Release notes for a single repository

To create release notes for a single repository (not a meta), run:

`php release_notes_single.php <repository> <current-version> <version-to-compare-with>`

For example:

`php release_notes_single.php ezpublish-kernel 7.3.0 7.2.0`

**Note**

- The script currently works only for the *latest* beta or rc release. If there is both a `-beta1` and `-beta2` tag, the script will produce correct release notes for `-beta1`.
