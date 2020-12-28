# eZ Platform release notes maker

This script prepares release notes for releases of [eZ Platform and eZ Platform Enterprise Edition](ezplatform.com).

## Prerequisites

To use the script you need a GitHub access token with `repo` scope.

Get it from your GitHub account's Settings > Developer settings > Personal access tokens.
Ensure that your token grants the full control of private repositories.
Otherwise it may be unable to generate release notes.

Place the token in a file named `token.txt` in the script's folder.

## Usage

Use the scripts via Command Prompt (Windows) or Terminal (macOS).

### Release notes for a meta-repository

To get full release notes for a tag in a meta-repository, enter the directory containing the script and run:

`php release_notes.php <meta-repository-name> <new tag> <previous tag>`

For example:

`php release_notes.php ezplatform v2.3.0 v2.2.0`

`php release_notes.php ezplatform v1.7.9-rc1 v1.7.8`

The script will produce a file for each component repository we cover in release notes,
as well as a separate file for the meta-repository.

### Release notes for a single repository

To create release notes for a single repository (not a meta), enter the directory containing the script and run:

`php release_notes_single.php <vendor>/<repository> <new tag> <previous tag>`

For example:

`php release_notes_single.php ezsystems/ezpublish-kernel v7.3.0 v7.2.0`
