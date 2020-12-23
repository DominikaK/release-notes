<?php

include 'release_notes_util.php';

// Checks if provided tag for the provided repository exists in the prepared list of all tags
function check_if_tag_exists($tag, $repo)
{
    $list_for_current_repo = $GLOBALS["list_of_tags"][$repo];

    if (in_array($tag, $list_for_current_repo)) {
        return true;
    } else {
        return false;
    }
}

// Calculates the previous beta/rc version
function calculate_previous_beta($version, $repo)
{
    do {
        // Divide the version into pieces
        $exploded_version = explode(".", $version);

        // Find the patch version in the sequence of all available patch versions
        $index = array_search($exploded_version[2], $GLOBALS["version_sequence"]);

        if ($index < count($GLOBALS["version_sequence"]) - 1) {
            $changed_version = $GLOBALS["version_sequence"][$index + 1];
            $exploded_version[2] = $changed_version;
            $version = implode(".", $exploded_version);
        }
        // If there is not lower patch version to pick,
        // select the next lower final version
        else {
            //TODO maybe prompt for providing the version manually?
            $patch = explode("-", $exploded_version[2]);
            $exploded_version[2] = $patch[0];
            $version = implode(".", $exploded_version);
            $version = calculate_previous_final($version, $repo);
            break;
        }
    } while (!check_if_tag_exists($version, $repo));

    return $version;
}

// Calculates the previous version for a final (non-beta/rc) version
function calculate_previous_final($version, $repo)
{
    do {
        // Divide the version into pieces
        $version_number = explode(".", $version);

        // Change major/minor/patch versions to the previous version
        if ($version_number[2] != "0") {
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

    } while (!check_if_tag_exists($version, $repo));

    return $version;
}

// Gets all tags from the provided repo
function get_list_of_tags($repo)
{
    $tags = get_from_github("https://api.github.com/repos/ezsystems/$repo/git/refs/tags");

    $raw_list_of_tags = json_decode($tags, JSON_OBJECT_AS_ARRAY);
    $list_of_tags = [];
    foreach ($raw_list_of_tags as $tag) {
        $tag_ref = $tag["ref"];
        $tag = str_replace("refs/tags/v", "", $tag_ref);
        array_push($list_of_tags, $tag);
    }

    return $list_of_tags;
}

// Gets list of bundles and version from a meta-repository's composer.lock
function get_bundles_from_meta($meta, $tag)
{
    // Get meta tag composer.json from GitHub
    $meta_composer = get_from_github("https://api.github.com/repos/ezsystems/$meta/contents/composer.lock?ref=v$tag");
    // Decode the json and get its content
    $decoded = base64_decode(json_decode($meta_composer)->content);

    // Get list of all bundles mentioned in the decoded composer.lock
    $raw_bundle_list = json_decode($decoded, JSON_OBJECT_AS_ARRAY)['packages'];
    $filtered_bundle_list = [];

    foreach ($raw_bundle_list as $repo) {
        if (in_array($repo['name'], $GLOBALS["repos_to_check"])) {
            $repo_name = str_replace("ezsystems/", "", $repo['name']);
            $version = strip_version($repo['version']);
            $filtered_bundle_list += [$repo_name => $version];
        }
    }
    return $filtered_bundle_list;
}

function calculate_previous($version, $repo)
{
    if ((strpos($version, 'rc') !== false) || (strpos($version, 'beta'))) {
        $previous_version = calculate_previous_beta($version, $repo);
    } // Determine previous meta-repository version for final tag:
    else {
        $previous_version = calculate_previous_final($version, $repo);
    }

    return $previous_version;
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
$tag = strip_version($tag);

// Create sequence of patch version numbers with rcs/betas
// There must be a smarter way to do this
$version_sequence_patch = [
    "",
    "-rc3", "-rc2", "-rc1",
    "-beta7", "-beta6", "-beta5", "-beta4", "-beta3", "-beta2", "-beta1"
];

$version_sequence = [];

for ($i = 5; $i >= 0; $i--) {
    foreach ($version_sequence_patch as $ver) {
        array_push($version_sequence, $i . $ver);
    }
}

// This is the list of all repositories we will check
$repos_to_check = [];

$repos_os = [
    "ezsystems/ezplatform-kernel",
    "ezsystems/ezplatform-admin-ui",
    "ezsystems/repository-forms",
    "ezsystems/ezplatform-solr-search-engine",
    "ezsystems/ezplatform-http-cache",
    "ezsystems/ezplatform-admin-ui-modules",
    "ezsystems/ezplatform-core",
    "ezsystems/ezplatform-rest",
    "ezsystems/ezplatform-design-engine",
    "ezsystems/ezplatform-standard-design",
    "ezsystems/ez-support-tools",
    "ezsystems/ezplatform-richtext",
    "ezsystems/ezplatform-graphql",
    "ezsystems/ezplatform-user",
    "ezsystems/doctrine-dbal-schema",
    "ezsystems/ezplatform-matrix-fieldtype",
    "ezsystems/ezplatform-content-forms",
    "ezsystems/ezplatform-query-fieldtype",
    "ezsystems/ezplatform-search",
    "ezsystems/ezplatform-cron",
];

$repos_ee = [
    "ezsystems/date-based-publisher",
    "ezsystems/ezplatform-ee-installer",
    "ezsystems/ezplatform-form-builder",
    "ezsystems/ezplatform-http-cache-fastly",
    "ezsystems/ezplatform-page-builder",
    "ezsystems/ezplatform-page-fieldtype",
    "ezsystems/flex-workflow",
    "ezsystems/ezplatform-workflow",
    "ezsystems/ezplatform-calendar",
    "ezsystems/ezplatform-version-comparison",
    "ezsystems/ezplatform-site-factory",
    "ezsystems/ezplatform-elastic-search-engine",
    "ezsystems/ezplatform-segmentation",
    "ezsystems/ezplatform-connector-dam",
    "ezsystems/ezplatform-permissions",
];

$repos_commerce = [
    "ezsystems/ezcommerce-erp-admin",
    "ezsystems/ezcommerce-base-design",
    "ezsystems/ezcommerce-shop",
    "ezsystems/ezcommerce-shop-ui",
    "ezsystems/ezcommerce-order-history",
    "ezsystems/ezcommerce-price-engine",
    "ezsystems/ezcommerce-admin-ui",
    "ezsystems/ezcommerce-page-builder",
    "ezsystems/ezcommerce-fieldtypes",
];

if ($meta == "ezplatform") {
    $repos_to_check = $repos_os;
} elseif ($meta == "ezplatformee" || $meta == "ezplatform-ee") {
    $repos_to_check = $repos_ee;
} elseif ($meta == "ezcommerce" || $meta == "commerce") {
    $repos_to_check = $repos_commerce;
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
foreach ($repos_to_check as $repo) {
    $repo = str_replace("ezsystems/", "", $repo);
    $list_for_current_repo = get_list_of_tags($repo);
    $list_of_tags += [$repo => $list_for_current_repo];
}

// Check if the meta tag exists
if (!check_if_tag_exists($tag, $GLOBALS["meta"])) {
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

$bundle_list = get_bundles_from_meta($meta, $tag);

// Determine previous meta-repository version for beta/rc:
if (count($argv) == 4) {
    // Use previous version provided as arguments, if it exists
    $previous_meta = substr($argv[3], 1);
} else {
    $previous_meta = calculate_previous($tag, $meta);
}

$previous_bundle_list = get_bundles_from_meta($meta, $previous_meta);

foreach ($bundle_list as $repo => $version) {
    $previous_version = $previous_bundle_list[$repo];

    $output = [];
    if ($version !== $previous_version) {
        strip_version($version);

        // If meta jumped a package version, get actual previous version for a package
        // for individual release notes
        $previous_single_version = calculate_previous($version, $repo);

        if ($previous_version == $previous_single_version) {
            array_push($output, $repo, $version, $previous_version, $previous_version);
        } else {
            array_push($output, $repo, $version, $previous_version, $previous_single_version);
        }

        array_push($output_list, $output);
    }
}

// Now we start getting the actual release notes

$rn_list = [];
$rn_list_to_delete = [];

foreach ($output_list as $repo) {
    create_release_notes($repo);
}

$meta_repo = [$meta, $tag, $previous_meta, $previous_meta];
create_release_notes($meta_repo);

// Create meta release notes
$final = "release_notes_" . $meta . "_" . $tag . ".md";
$ffinal = fopen($final, "w+");

fwrite($ffinal, "# $meta v$tag change log\n\n");

if ($meta == "ezplatformee" || $meta == "ezplatform-ee" || $meta == "ezcommerce") {
    fwrite($ffinal, "Corresponding eZ Platform release: https://github.com/ezsystems/ezplatform/releases/tag/v" . $tag . "\n\n");
} else {
    fwrite($ffinal, "Corresponding eZ Platform Enterprise Edition release: https://github.com/ezsystems/ezplatform-ee/releases/tag/v" . $tag . "\n\n");
}

fwrite($ffinal, "Changes since " . $previous_meta . "\n\n");

foreach ($rn_list as $repo_rn) {
    $current_repo_rn = file_get_contents($repo_rn);
    fwrite($ffinal, "## ");
    fwrite($ffinal, $current_repo_rn);
}

foreach ($rn_list_to_delete as $repo_rn) {
    unlink($repo_rn);
}

print_r("\n\nRelease notes for meta-repository created in " . $final . ".\n\n");

// Print out JIRA tickets the script couldn't get info for, if there are any
if (!empty($failed_tickets)) {
    print_r("\nCould not get information for the following tickets: \n");
    foreach ($failed_tickets as $repository => $ticket) {
        print_r($repository . ": " . $ticket . "\n");
    }
}

// Print out repositories the script couldn't get info for, if there are any
if (!empty($failed_repositories)) {
    print_r("\nCould not get information for the following repositories: \n");
    foreach ($failed_repositories as $repository) {
        print_r($repository . "\n");
    }
}

fclose($ffinal);
