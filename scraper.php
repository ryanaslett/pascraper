<?php

require 'vendor/autoload.php';
require 'user_password.php';

use Goutte\Client;

// "Needs work" issue status ID.
const PROJECTAPP_SCRAPER_NEEDS_WORK = 13;
const PROJECTAPP_SCRAPER_NEEDS_REVIEW = 8;
const PROJECTAPP_SCRAPER_DUPLICATE = 3;
const PROJECTAPP_SCRAPER_POSTPONED = 4;
const PROJECTAPP_SCRAPER_POSTPONED_INFO = 16;
const PROJECTAPP_SCRAPER_WONTFIX = 5;

// Perform a user login.
global $client;
$client = new Client();
$crawler = $client->request('GET', 'http://drupal.org/user');
$form = $crawler->selectButton('Log in')->form();
// $user and $password must be set in user_password.php.
$crawler = $client->submit($form, array('name' => $user, 'pass' => $password));

$login_errors = $crawler->filter('.messages-error');
if ($login_errors->count() > 0) {
  print "Login failed.\n";
  exit(1);
}

// Get all "needs review" issues.
$crawler = $client->request('GET', 'http://drupal.org/project/issues/projectapplications?status=8');
$issues = $crawler->filterXPath('//tbody/tr/td[1]/a');

if ($issues->count() == 0) {
  print "No issues found.\n";
  exit(2);
}

// Retrieve the advanced search form for checking multiple applications.
// We cannot just fetch with the URL
// '?submitted=' . urlencode($user_name) . '&status[]=Open' because the status
// does not work. Instead we submit the issue search form.
$issue_search = $client->request('GET', 'http://drupal.org/project/issues/search/projectapplications');
$search_form = $issue_search->filter('#edit-submit-project-issue-search-project')->form();

$links = $issues->links();

// Go to each issue.
foreach ($links as $link) {
  $issue_page = $client->click($link);
  $issue_summary = $issue_page->filter('.node-content');
  // Initialize empty comment list that shoudl be posted.
  $post = array();
  // Do not touch the issue's status per default.
  $status = NULL;

  // Extract all links out of the issue summary to determine the Git clone URL
  // from the project page link.
  $summary_links = $issue_summary->filterXPath('//@href');
  $git_url = NULL;
  foreach ($summary_links as $reference) {
    if (preg_match('/http(s)?:\/\/drupal\.org\/sandbox\//', $reference->value)) {
      $git_url = str_replace('https://', 'http://', $reference->value);
      $git_url = str_replace('http://', 'http://git.', $git_url) . '.git';
      break;
    }
  }
  if (!$git_url) {
    // Search for other git repository links.
    $text = $issue_summary->text();
    $matches = array();
    // There are a couple of possible patterns:
    // http://git.drupal.org/sandbox/<user>/<nid>.git
    // <user>@git.drupal.org:sandbox/<user>/<nid>.git
    // http://drupalcode.org/sandbox/<user>/<nid>.git
    // git.drupal.org:sandbox/<user>/<nid>.git
    preg_match('/http:\/\/git\.drupal\.org\/sandbox\/.+\.git|[^\s]+@git\.drupal\.org:sandbox\/.+\.git|http:\/\/drupalcode\.org\/sandbox\/.+.git|git\.drupal\.org:sandbox\/.+\.git/', $text, $matches);
    if (empty($matches)) {
      // Set the issue to "needs work" as the link to the project page is missing.
      $post[] = 'Link to the project page and git clone command are missing in the issue summary, please add them.';
      $status = PROJECTAPP_SCRAPER_NEEDS_WORK;
    }
    else {
      $url = $matches[0];
      preg_match('/sandbox\/.*\.git/', $url, $matches);
      $git_url = 'http://git.drupal.org/' . $matches[0] . '.git';
    }
  }

  // Get the issue summary + all commments.
  $issue_thread = $issue_page->filter('#content');

  if ($git_url) {
    // Check if someone already posted a link to ventral.org automated reviews.
    $issue_links = $issue_thread->filterXPath("//@href[starts-with(., 'http://ventral.org/pareview')]");
    if ($issue_links->count() == 0) {
      // Invoke pareview.sh now to check automated review errors.
      $pareview_output = array();
      $return_var = 0;
      exec('pareview.sh ' . escapeshellarg($git_url), $pareview_output, $return_var);
      if ($return_var == 1) {
        print 'Git clone failed for ' . $git_url . ', issue: ' . $client->getRequest()->getUri();
      }
      // If there are more than 30 lines output then we assume that some errors
      // should be fixed.
      elseif (count($pareview_output) > 30) {
        $post[] = 'There are some errors reported by automated review tools, did you already check them? See http://ventral.org/pareview/' . str_replace(array('/', '.', ':'), '', $git_url);
        $status = PROJECTAPP_SCRAPER_NEEDS_WORK;
      }
    }
  }

  // Search for multiple applications for this user.
  $user_name = $issue_page->filterXPath("///div[@class = 'node clear-block node-type-project_issue']/div[@class = 'submitted']/a")->text();
  $search_results = $client->submit($search_form, array('submitted' => $user_name, 'status' => 'Open'));
  $application_issues = $search_results->filterXPath('//tbody/tr/td[1]/a')->links();
  if (count($application_issues) > 1) {
    $comment[] = <<<COMMENT
<dl>
<dt>Multiple Applications</dt>
<dd>It appears that there have been multiple project applications opened under your username:

COMMENT;
    foreach ($application_issues as $count => $application_issue) {
      $comment[] = 'Project ' . ($count + 1) . ': ' . $application_issue->getUri();
    }
    $comment[] = <<<COMMENT
As successful completion of the project application process results in the applicant being granted the 'Create Full Projects' permission, there is no need to take multiple applications through the process. Once the first application has been successfully approved, then the applicant can promote other projects without review. Because of this, posting multiple applications is not necessary, and results in additional workload for reviewers ... which in turn results in longer wait times for everyone in the queue.  With this in mind, your secondary applications have been marked as 'closed(duplicate)', with only one application left open (chosen at random).

If you prefer that we proceed through this review process with a different application than the one which was left open, then feel free to close the 'open' application as a duplicate, and re-open one of the project applications which had been closed.</dd>
</dl>
COMMENT;
    // Leave the last application open and just post the comment.
    $open_application = array_pop($application_issues);
    $open_application_page = $client->click($open_application);
    projectapp_scraper_post_comment($open_application_page, $comment);

    // Close all other applications.
    foreach ($application_issues as $application_issue) {
      $duplicate_page = $client->click($application_issue);
      projectapp_scraper_post_comment($duplicate_page, $comment, PROJECTAPP_SCRAPER_DUPLICATE);
    }
  }

  // Post a hint to the review bonus program.
  $issue_text = $issue_thread->text();
  if (stripos($issue_text, 'review bonus') === FALSE) {
    $post[] = 'We are currently quite busy with all the project applications and I can only review projects with a <a href="http://drupal.org/node/1410826">review bonus</a>. Please help me reviewing and put yourself on the <a href="http://drupal.org/project/issues/search/projectapplications?status[]=8&status[]=14&issue_tags=PAReview%3A+review+bonus">PAReview: review bonus high priority list</a>. Then I\'ll take a look at your project right away :-)';
  }

  if (!empty($post)) {
    projectapp_scraper_post_comment($issue_page, $post, $status);
  }
}

// Close "needs work" applications that got no update in more than 10 weeks.
$search_results = $client->submit($search_form, array('status' => array(PROJECTAPP_SCRAPER_NEEDS_WORK, PROJECTAPP_SCRAPER_POSTPONED, PROJECTAPP_SCRAPER_POSTPONED_INFO)));
// Sort by last updated date.
$sorted = $client->click($search_results->selectLink('Last updated')->link());
$old_issues = $sorted->filterXPath('//tbody/tr/td[1]/a')->links();
// Extract the updated intervals from the issue table.
$intervals = $sorted->filterXPath('//tbody/tr/td[7]');

$comment = 'Closing due to lack of activity. Feel free to reopen if you are still working on this application.';

foreach ($intervals as $count => $interval) {
  $updated = strtotime(trim($interval->nodeValue));
  $diff = $updated - time();
  // 10 weeks == 6048000 seconds.
  if ($diff > 6048000) {
    $issue_page = $client->click($old_issues[$count]);
    projectapp_scraper_post_comment($issue_page, $comment, PROJECTAPP_SCRAPER_WONTFIX);
  }
  else {
    // We reached the threshold of 10 weeks, all further issues are younger. So
    // we can stop here.
    break;
  }
}

/**
 * Helper function to either output the issue comment on a dry-run or post a new
 * comment to the issue.
 */
function projectapp_scraper_post_comment($issue_page, $post, $status = NULL) {
  global $argv;
  global $client;
  if (!is_array($post)) {
    $post = array($post);
  }

  $post[] = "<i>I'm a robot and this is an automated message from <a href=\"http://drupal.org/sandbox/klausi/1938730\">Project Applications Scraper</a>.</i>";

  if (isset($argv[1]) && $argv[1] == 'dry-run') {
    // Dry run, so just print out the suggested comment.
    $output = array(
      'issue' => $client->getRequest()->getUri(),
      'comment' => $post,
      'status' => $status,
    );
    print_r($output);
  }
  else {
    // Production run: post the comment to the drupal.org issue.
    $comment_form = $issue_page->selectButton('Save')->form();
    $comment = implode("\n\n", $post);
    $form_values = array('comment' => $comment);
    if ($status) {
      $form_values['sid'] = $status;
    }
    $client->submit($comment_form, $form_values);
  }
}
