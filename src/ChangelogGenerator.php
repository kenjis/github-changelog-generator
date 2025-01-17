<?php declare(strict_types=1);

namespace ins0\GitHub;

use Exception;
use DateTimeInterface;
use DateTime;
use stdClass;

/**
 * Generates a changelog using your GitHub repository's releases, issues and pull-requests.
 *
 * @version 0.2.1
 * @author Marco Rieger (ins0)
 * @author Nathan Bishop (nbish11) (Contributor and Refactorer)
 * @author Tony Murray (murrant) (Contributor)
 * @copyright (c) 2015 Marco Rieger
 * @license MIT
 */
 class ChangelogGenerator
 {
     const LABEL_TYPE_ADDED = 'type_added';
     const LABEL_TYPE_CHANGED = 'type_changed';
     const LABEL_TYPE_DEPRECATED = 'type_deprecated';
     const LABEL_TYPE_REMOVED = 'type_removed';
     const LABEL_TYPE_FIXED = 'type_fixed';
     const LABEL_TYPE_SECURITY = 'type_security';
     const LABEL_TYPE_PR = 'type_pr';
     const LABEL_TYPE_UNKNOWN = '';

     private $repository;
     private $currentIssues;

     private $issueLabelMapping = [
         self::LABEL_TYPE_ADDED      => ['feature'],
         self::LABEL_TYPE_CHANGED    => ['enhancement'],
         //self::LABEL_TYPE_DEPRECATED => [],
         //self::LABEL_TYPE_REMOVED    => [],
         self::LABEL_TYPE_FIXED      => ['bug'],
         //self::LABEL_TYPE_SECURITY   => []
     ];

     private $typeHeadings = [
         self::LABEL_TYPE_ADDED => "### Added",
         self::LABEL_TYPE_CHANGED => "### Changed:",
         self::LABEL_TYPE_DEPRECATED => "### Deprecated",
         self::LABEL_TYPE_REMOVED => "### Removed",
         self::LABEL_TYPE_FIXED => "### Fixed",
         self::LABEL_TYPE_SECURITY => "### Security",
         self::LABEL_TYPE_PR => "### Merged pull requests:",
     ];

     protected static $supportedEvents = ['merged', 'referenced', 'closed', 'reopened'];

     /**
      * Constructs a new instance.
      *
      * @param Repository $repository [description]
      * @param array $issueMappings type => issue tags/labels
      * @param array $typeHeadings type => heading text
      */
     public function __construct(Repository $repository, array $issueMappings = [], array $typeHeadings = [])
     {
         $this->repository = $repository;
         $this->issueLabelMapping = array_merge($this->issueLabelMapping, $issueMappings);
         $this->typeHeadings = array_replace($this->typeHeadings, $typeHeadings);
     }

     /**
      * Generate changelog data.
      *
      * @return string [description]
      */
     public function generate(): string
     {
         $this->currentIssues = null;
         $releases = $this->collectReleaseIssues();
         $data = "# Changelog\n> This project adheres to [Semantic Versioning](http://semver.org/).\n\n";

         foreach ($releases as $release) {
             // ignore pre-releases or releases that have no issues
             if (empty($release->issues)) {
                 continue;
             }

             $publishDate = date('Y-m-d', strtotime($release->published_at));
             $data .= sprintf("## [%s](%s) - %s\n", $release->tag_name, $release->html_url, $publishDate);

             foreach ($release->issues as $type => $currentIssues) {
                 $data .= $this->getHeaderForType($type);

                 foreach ($currentIssues as $issue) {
                     $data .= sprintf("- %s [\#%s](%s)\n", $issue->title, $issue->number, $issue->html_url);
                 }

                 $data .= "\n";
             }
         }

         return $data;
     }

     /**
      * Get all issues from release tags.
      *
      * @param DateTimeInterface|null $startDate [description]
      *
      * @return array [description]
      *
      * @throws Exception
      */
     private function collectReleaseIssues(DateTimeInterface $startDate = null): array
     {
         $releases = iterator_to_array($this->repository->getReleases());

         if (empty($releases)) {
             throw new Exception('No releases found for this repository');
         }

         $releasesWithIssues = [];

         do {
             $currentRelease = (object) current($releases);

            if ($startDate && date_diff(new DateTime($currentRelease->published_at), $startDate)->days <= 0) {
                continue;
            }

             $lastRelease = next($releases);
             if ($lastRelease === false) {
                 $lastReleaseDate = null;
             } else{
                 $lastRelease = (object) $lastRelease;
                 $lastReleaseDate = new DateTime($lastRelease->published_at);
             }
             prev($releases);

             // @FIXME Collecting issues from the release date is not accurate.
             $currentRelease->issues = $this->collectIssues($lastReleaseDate);

             $releasesWithIssues[] = $currentRelease;

         } while (next($releases));

         return $releasesWithIssues;
     }

     /**
      * Get all issues from release date.
      *
      * @param DateTimeInterface|null $lastReleaseDate [description]
      *
      * @return array [description]
      */
     private function collectIssues(DateTimeInterface $lastReleaseDate = null): array
     {
         if (! $this->currentIssues) {
             $this->currentIssues = [];

             foreach ($this->repository->getIssues(['state' => 'closed']) as $issue) {
                 $this->currentIssues[] = $issue;
             }
         }

         $issues = [];

         foreach ($this->currentIssues as $x => $issue) {
             $issue = (object) $issue;
             if (new DateTime($issue->closed_at) > $lastReleaseDate || $lastReleaseDate == null) {
                 unset($this->currentIssues[$x]);

                 $type = $this->determineChangeType($issue);

                 if ($type) {
                     $events = $this->repository->getIssueEvents($issue->number);

                     foreach ($events as $event) {
                         $event = (object) $event;
                         if (in_array($event->event, self::$supportedEvents) && !empty($event->commit_id)) {
                             $issues[$type][] = $issue;
                             break;
                         }
                     }
                 }
             }
         }

         return $issues;
     }

     private function getHeaderForType(string $type): string
     {
         if (isset($this->typeHeadings[$type])) {
             return sprintf($this->typeHeadings[$type]) . "\n";
         }

         // fabricate a header based on the type name
         $header = ucfirst(str_replace('type_', '', $type));
         return "### $header\n";
     }

     /**
      * Gets the issue type from issue labels.
      *
      * @param array $labels [description]
      *
      * @return mixed [description]
      */
     private function determineChangeType(stdClass $issue): string
     {
        // Determine change type from an issue's labels
        foreach ($this->issueLabelMapping as $changeType => $labelsUsedToDetermineChangeType) {
            foreach ((array) $labelsUsedToDetermineChangeType as $labelForChangeType) {
                foreach ($issue->labels as $issueLabel) {
                    $labelName = $issueLabel->name ?? '';
                    if (strcasecmp($labelName, $labelForChangeType) === 0) {
                        return $changeType;
                    }
                }
            }
        }

         // Couldn't find a change type based of labels, maybe it's a PR with no labels
         if (isset($issue->pull_request)) {
             return self::LABEL_TYPE_PR;
         }

         // No change type could be determined
         return self::LABEL_TYPE_UNKNOWN;
     }
 }
