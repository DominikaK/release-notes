<?php

include 'release_notes_util.php';

// Checks if provided tag for the provided repository exists in the prepared list of all tags
function check_if_tag_exists($tag, $repo)
{
    $list_for_current_repo = $GLOBALS["list_of_tags"][$repo];

    if (in_array($tag, $list_for_current_repo))
    {
        return true;
    }
    else {
        return false;
    }
}



// Calculates the newest beta/rc version from the version provided as "@beta" or "@rc"
function calculate_beta($version, $repo)
{
    while (!check_if_tag_exists($version, $repo))
    {
        // Clean up the at sign
        $version = str_replace("@rc", "-rc", $version);
        $version = str_replace("@beta", "-beta", $version);

        $exploded_version = explode(".", $version);

        // Find the patch version number in the sequence of all patch version
        $index = array_search($exploded_version[2], $GLOBALS["version_sequence"]);

        if ($index < count($GLOBALS["version_sequence"]))
        {
            // Pick the next patch version in the sequence and glue the version again
            $changed_version = $GLOBALS["version_sequence"][$index + 1];
            $exploded_version[2] = $changed_version;
            $version = implode(".", $exploded_version);
        }
        else {
            //TODO maybe prompt for providing the version manually?
            $version = null;
            break;
        }
    }

    return $version;
}

// Calculates the previous beta/rc version
// This does pretty much the same as calculate_beta, but I've run out of ideas
function calculate_previous_beta($version, $repo)
{
     do {
        $version = calculate_beta($version, $repo);
        $exploded_version = explode(".", $version);
        $index = array_search($exploded_version[2], $GLOBALS["version_sequence"]);

        if ($index < count($GLOBALS["version_sequence"])-1) {
            $changed_version = $GLOBALS["version_sequence"][$index + 1];
            $exploded_version[2] = $changed_version;
            $version = implode(".", $exploded_version);
        } else {
            //TODO maybe prompt for providing the version manually?
            $patch = explode("-", $exploded_version[2]);
            $exploded_version[2] = $patch[0];
            $version = implode(".", $exploded_version);
            $version = calculate_previous_final($version, $repo);
            break;
        }
    }
    while (!check_if_tag_exists($version, $repo));

    return $version;
}


// Calculates the previous version for a final (non-beta/rc) version
function calculate_previous_final($version, $repo)
{
    do {
        // Divide the version into pieces
        $version_number = explode(".", $version);

        // Change major/minor/patch versions to the previous version
        if ($version_number[2] != "0")
        {
            $version_number[2]--;
        } elseif ($version_number[1] != "0") {
            $version_number[1]--;
        } else {
            if ($version_number[0] != 0) {
                $version_number[0]--;
            } else {
                $version = null;
                break;
            }
        }

        // Glue the version together
        $version = implode(".", $version_number);

    }
    while (!check_if_tag_exists($version, $repo));

    return $version;
}

// Gets all tags from the provided repo
function get_list_of_tags($repo)
{
    $tags = get_from_github("https://api.github.com/repos/ezsystems/$repo/git/refs/tags");

    $raw_list_of_tags = json_decode($tags, JSON_OBJECT_AS_ARRAY);
    $list_of_tags = [];
    foreach ($raw_list_of_tags as $tag)
    {
        $tag_ref = $tag["ref"];
        $tag = str_replace("refs/tags/v", "", $tag_ref);
        array_push($list_of_tags, $tag);
    }

    return $list_of_tags;
}

// Gets list of bundles and version from a meta-repository's composer.json
function get_bundles_from_meta($meta, $tag)
{
    // Get meta tag composer.json from GitHub
    $meta_composer = get_from_github("https://api.github.com/repos/ezsystems/$meta/contents/composer.json?ref=v$tag");
    // Decode the json and get its content
    $decoded = base64_decode(json_decode($meta_composer)->content);

    // Get list of all bundles mentioned in the decoded composer.json
    $full_bundle_list = json_decode($decoded, JSON_OBJECT_AS_ARRAY)['require'];
    $filtered_bundle_list = [];

    // Go through all bundles and find the ones on our list
    foreach ($full_bundle_list as $repo => $version) {
        if (in_array($repo, $GLOBALS["repos_to_check"])) {
            $repo = str_replace("ezsystems/", "", $repo);
            $filtered_bundle_list += [$repo => $version];
        }
    }

    // Strip tilde and caret from version number
    foreach ($filtered_bundle_list as $repo => $version) {
        // Strip tilde and caret from version number
        if (strpos($version, '~') !== false) {
            $version = str_replace("~", "", $version);
        }
        if (strpos($version, '^') !== false) {
            $version = str_replace("^", "", $version);
        }
        $filtered_bundle_list[$repo] = $version;
    }

    return $filtered_bundle_list;
}

// Start command and interpret arguments

$token = '';

// Get Github auth token from file
if (file_exists('token.txt')) {
    $tokenFile = "token.txt";
    $tokenFile_content = file($tokenFile);
    // Assign Github auth token to $token
    $GLOBALS["token"] = $tokenFile_content[0];
} else {
    exit("Please provide your GitHub authentication token in a 'token.txt' file in this folder.");
}

// Make sure the tag and meta are provided
if (!$argv[2]) {
    exit("Please provide the meta-repository and the tag name.");
}

// Get the repository and tag from arguments
$meta = $argv[1];
$tag = $argv[2];
if ($tag[0] = "v")
{
    $tag = substr($tag, 1);
}

// Create sequence of patch version numbers with rcs/betas
// There must be a smarter way to do this
$version_sequence_patch = [
    "",
    "-rc", "-rc3", "-rc2", "-rc1",
    "-beta", "-beta3", "-beta2", "-beta1"
];

$version_sequence = [];

for ($i = 5; $i >= 0; $i--) {
    foreach ($version_sequence_patch as $ver)
    {
        array_push($version_sequence, $i . $ver);
    }
}

// This is the list of all repositories we will check
$repos_to_check = [];

$repos_os = [
    "ezsystems/ezpublish-kernel",
    "ezsystems/ezplatform-admin-ui",
    "ezsystems/repository-forms",
    "ezsystems/ezplatform-solr-search-engine",
    "ezsystems/ezplatform-http-cache",
    "ezsystems/ezplatform-admin-ui-modules",
    "ezsystems/ezplatform-design-engine",
    "ezsystems/ezplatform-standard-design",
    "ezsystems/ez-support-tools"
    //"ezsystems/ezplatform-richtext"
];

$repos_ee = [
    "ezsystems/date-based-publisher",
    "ezsystems/ezplatform-ee-installer",
    "ezsystems/ezplatform-form-builder",
    "ezsystems/ezplatform-http-cache-fastly",
    "ezsystems/ezplatform-page-builder",
    "ezsystems/ezplatform-page-fieldtype",
    "ezsystems/flex-workflow",
    //"ezsystems/ezplatform-workflow"
];

if ($meta == "ezplatform") {
    $repos_to_check = $repos_os;
}
elseif ($meta == "ezplatformee" || $meta == "ezplatform-ee") {
    $repos_to_check = $repos_ee;
}
else {
    print_r("Unknown meta-repository");
    exit;
}

// Global list of tags for all our repos
$list_of_tags = [];

// Add tags in meta as the first item in the global list of tags
$meta_tags = get_list_of_tags($meta);
$list_of_tags += [$meta => $meta_tags];

// Get list of tags for all repos, to avoid requesting GitHub every time
foreach ($repos_to_check as $repo)
{
    $repo = str_replace("ezsystems/", "", $repo);
    $list_for_current_repo = get_list_of_tags($repo);
    $list_of_tags += [$repo => $list_for_current_repo];
}

// Check if the meta tag exists
if (!check_if_tag_exists($tag, $GLOBALS["meta"]))
{
    print_r("There is no such tag in the meta-repository");
    exit;
}

// This will be the list of all repos to make RN for
// with the from and to version
$output_list = [];

// Array of JIRA ticket the script could not get information from
$failed_tickets = [];

// Array of repositories the script could not get information from
// because it could not figure out the current or the previous version
$failed_repositories = [];

// Figure out the version numbers to check
// beta/rc and final versions have to be treated differently
// For beta/rc:
if ((strpos($tag, 'rc') !== false) || (strpos($tag, 'beta'))) {
    // Get meta tag composer.json from GitHub
    $meta_composer = get_from_github("https://api.github.com/repos/ezsystems/$meta/contents/composer.json?ref=v$tag");
    // Decode the json and get its content
    $decoded = base64_decode(json_decode($meta_composer)->content);

    // Get list of all bundles mentioned in the decoded composer.json
    $full_bundle_list = json_decode($decoded, JSON_OBJECT_AS_ARRAY)['require'];
    $filtered_bundle_list = [];

    // Go through bundle list and filter the ones that are on the list
    foreach ($full_bundle_list as $repo=>$version) {
        if (in_array($repo, $GLOBALS["repos_to_check"])) {
            $repo = str_replace("ezsystems/", "", $repo);
            $filtered_bundle_list += [$repo => $version];
        }
    }

    // Strip tilde and caret from version number
    foreach ($filtered_bundle_list as $repo=>$version) {
        if (strpos($version, '~') !== false) {
            $version = str_replace("~", "", $version);
        }
        if (strpos($version, '^') !== false) {
            $version = str_replace("^", "", $version);
        }
        $filtered_bundle_list[$repo] = $version;
    }

    // Clean up the current version and calculate the previous one
    foreach ($filtered_bundle_list as $repo=>$version) {
        if ((strpos($version, 'rc') !== false) || (strpos($version, 'beta')))
        {
            // This bad hack ensures that both the old and new versions are nice and clean
            $old_version = calculate_beta($version, $repo);
            $new_version = calculate_previous_beta($version, $repo);
            $version = $old_version;
        }
        else{
            $new_version = calculate_previous_final($version, $repo);
        }

        $output = [];
        array_push($output, $repo, $version, $new_version);
        array_push($output_list, $output);
    }

    // Previous meta here is not needed for packages, but for displaying in output file
    $previous_meta = calculate_previous_beta($tag, $meta);
}
// For final version:
else {
    $previous_meta = calculate_previous_final($tag, $meta);

    $filtered_bundle_list = get_bundles_from_meta($meta, $tag);
    $filtered_previous_bundle_list = get_bundles_from_meta($meta, $previous_meta);
    $output = [];

    // Find current and previous version for each repo and output it
    foreach ($filtered_bundle_list as $repo => $version) {
        if ($version !== null && array_key_exists($repo, $filtered_previous_bundle_list)) {
            // If the version has changed for the current repository, add it to the list
            if ($version !== $filtered_previous_bundle_list[$repo]) {

                $new_version = $filtered_previous_bundle_list[$repo];

                $output = [];
                array_push($output, $repo, $version, $new_version);
                array_push($output_list, $output);
            }
        }
    }
}

// Now we start getting the actual release notes

$rn_list = [];

foreach ($output_list as $repo) {
    create_release_notes($repo);
}

// Create meta release notes
$final = "release_notes_" . $meta . "_" . $tag . ".md";
$ffinal = fopen($final, "w+");

fwrite($ffinal, "Change log:\n\n");


//TODO calculate previous_meta in case of a beta/rc meta tag
fwrite($ffinal, "Changes since " . $previous_meta . "\n\n");

foreach ($rn_list as $repo_rn)
{
    $current_repo_rn = file_get_contents($repo_rn);
    fwrite($ffinal, "## ");
    fwrite($ffinal, $current_repo_rn);
}

print_r("\n\nRelease notes for meta-repository created in " . $final . ".\n\n");

// Print out JIRA tickets the script couldn't get info for, if there are any
if (!empty($failed_tickets))
{
    print_r("\nCould not get information for the following tickets: \n");
    foreach ($failed_tickets as $repository=>$ticket)
    {
        print_r($repository . ": " . $ticket . "\n");
    }

}

// Print out repositories the script couldn't get info for, if there are any
if (!empty($failed_repositories))
{
    print_r("\nCould not get information for the following repositories: \n");
    foreach ($failed_repositories as $repository)
    {
        print_r($repository . "\n");
    }

}

fclose($ffinal);
