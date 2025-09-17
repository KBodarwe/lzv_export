<?php

/**
 * @file plugins/importexport/lzv/filter/ArticleLZVXmlFilter.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PublicationProcessExport
 *
 * @brief Class that converts a Article to a PubMed XML document.
 */
namespace APP\plugins\importexport\lzv\classes;

use APP\decision\Decision;
use APP\facades\Repo;
use APP\classes\submission\Submission;
use PKP\core\PKPApplication;
use Illuminate\Support\Facades\DB;
use DOMDocument;
use DOMElement;
use PKP\config\Config;
use PKP\file\FileManager;


class PublicationProcessExport
{
    public function construct()
    {
        
    }


    /**
     * Build the publication_process.xml document and export the files to the correct location within the SIP.
     * @param Submission $submission 
     *
     * @return \DOMDocument
     */
    public function export($submission)
    {
        // Create the XML document
        $doc = new DOMDocument('1.0');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $doc->encoding = 'UTF-8';

        //Always work with one Submission only. Multiple Selected Submissions are handled elsewhere
        $rootNode = $this->createPublicationProcessNode($doc, $submission);

        $doc->appendChild($rootNode);
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', 'http://purl.org/dc/elements/1.1/');
        $rootNode->setAttribute('xsi:schemaLocation', '\APP\plugins\importexport\lzv\schema\publication_process.xsd');

        return $doc;
    }


    //
    // Submission conversion functions
    //
    /**
     * Create and return a submission node.
     *
     * @param \DOMDocument $doc
     * @param Submission $submission
     *
     * @return \DOMElement
     */
    public function createPublicationProcessNode($doc, $submission)
    {
        $submissionNode = $doc->createElement('article');


        $this->addArticleSource($doc, $submissionNode, $submission);
        $this->addManuscript($doc, $submissionNode, $submission);
        $this->addPeerReview($doc, $submissionNode, $submission);
        $this->addPublicationProof($doc, $submissionNode, $submission);

        return $submissionNode;
    }


    /**
     * Create and add Article Source nodes to a submission node.
     *
     * @param \DOMDocument $doc
     * @param \DOMElement $submissionNode
     * @param Submission $submission
     */
    public function addArticleSource($doc, $submissionNode, $submission)
    {
        $publication = $submission->getCurrentPublication();
        //Create article_source node
        $submissionNode->appendChild($articleSourceNode = $doc->createElement('article_source'));
        //fill node with DC Terms
        $this->addDcTerms($doc, $articleSourceNode, $publication);
    }


/**
 * Add Dublin Core (DC) terms to the article source node.
 *
 * This function appends various Dublin Core elements such as title, creator,
 * subject, description, publisher, contributor, date, type, format, identifier,
 * source, language, relation, coverage, and rights to the specified
 * article source node. These elements are extracted from the provided
 * publication data and added with appropriate attributes including locale
 * and type.
 *
 * @param \DOMDocument $doc The XML document being constructed.
 * @param \DOMElement $articleSourceNode The article source node to which the DC terms will be appended.
 * @param Submission $submission The submission object containing publication data.
 */
    public function addDcTerms($doc, $articleSourceNode, $publication)
    {
        $locale = $publication->getDefaultLocale();

        //title
        $articleSourceNode->appendChild($titlenode = $doc->createElement('dc:title', htmlspecialchars(strip_tags($publication->getLocalizedFullTitle($locale)))));
        $titlenode->setAttribute('xml:lang', strip_tags(substr($locale, 0, 2)));
        //creator
        $authors = $publication->getData('authors');
            foreach ($authors as $author) {
                $articleSourceNode->appendChild($doc->createElement('dc:creator',$author->getFullName(false, false, $locale)));
            }
        
        //subject
        if ($keywords = $publication->getData('keywords')) {
            foreach ($keywords as $keywordlocale => $localeKeywords) {
                foreach ($localeKeywords as $keyword) {
                    $articleSourceNode->appendChild($keywordnode = $doc->createElement('dc:subject', $keyword));
                    $keywordnode->setAttribute('xml:lang', strip_tags(substr($keywordlocale, 0, 2)));
                }
            }
        }

        //description
        $abstracts = $publication->getData('abstract');
        foreach ($abstracts as $abstractlocale => $abstract) {
            if ($abstract != '') {
                $articleSourceNode->appendChild($descriptionnode = $doc->createElement('dc:description', htmlspecialchars(strip_tags($abstract))));
                $descriptionnode->setAttribute('xml:lang', strip_tags(substr($abstractlocale, 0, 2)));
            }
        }

        //publisher
        $issueId = $publication->getData('issueId');
        $journalTitles = $this->getJournalNameDB($issueId);
        foreach ($journalTitles as $journalTitle) {
            if ($journalTitle->setting_value != '' && $journalTitle->setting_value != null) {
                $articleSourceNode->appendChild($publishernode = $doc->createElement('dc:publisher', $journalTitle->setting_value));
                $publishernode->setAttribute('xml:lang', strip_tags(substr($journalTitle->locale, 0, 2)));
            }

        }

        //contributor
        if ($supportingAgencies = $publication->getData('supportingAgencies')) {
            foreach ($supportingAgencies as $contributorlocale => $localeSupportingAgencies) {
                foreach ($localeSupportingAgencies as $i => $supportingAgency) {
                    $articleSourceNode->appendChild($contributornode = $doc->createElement('dc:contributor', strip_tags($supportingAgency)));
                    $contributornode->setAttribute('xml:lang', strip_tags(substr($contributorlocale, 0, 2)));
                }
            }
        }

        //date
        if ($datePublished = $publication->getData('datePublished')) {
            $articleSourceNode->appendChild($doc->createElement('dc:date', date('Y-m-d', strtotime($datePublished))));
        }

        //type
        //COAR Vocabulary: 'journal article' see: https://vocabularies.coar-repositories.org/resource_types/c_6501 
        $articleSourceNode->appendChild($typenode = $doc->createElement('dc:type', 'journal article'));
        $typenode->setAttribute('xml:lang', 'en-US');

        //format
        $galleys = $publication->getData('galleys');
        foreach ($galleys as $galley) {
            $submissionFileId = $galley->getData('submissionFileId');
            if ($submissionFileId && $submissionFile = Repo::submissionFile()->get($submissionFileId)) {
                $articleSourceNode->appendChild($doc->createElement('dc:format', strip_tags($submissionFile->getData('mimetype'))));
            }
        }

        //identifier
        $articleSourceNode->appendChild($identifiernode = $doc->createElement('dc:identifier', $publication->getData('urlPath')));
        $identifiernode->setAttribute('type', 'url');
        if ($doi = $publication->getStoredPubId('doi')) {
            $articleSourceNode->appendChild($doinode = $doc->createElement('dc:identifier', $doi));
            $doinode->setAttribute('type', 'doi');
        }

        //source
        $volumeYear = $this->getIssueVolumeYearDB($publication->getData('issueId'));     
        foreach ($journalTitles as $journalTitle) {
            if ($journalTitle->setting_value != '' && $journalTitle->setting_value != null) {
                $articleSourceNode->appendChild($doc->createElement('dc:source', $journalTitle->setting_value . "; " . $volumeYear));
                $publishernode->setAttribute('xml:lang', strip_tags(substr($journalTitle->locale, 0, 2)));
            }

        }
        $issns = $this->getIssnDB($publication->getData('issueId'));
        foreach ($issns as $issn) {
            $articleSourceNode->appendChild($doc->createElement('dc:source', $issn->setting_value));
        }

        //language
        $articleSourceNode->appendChild($doc->createElement('dc:language', substr($locale, 0, 2)));
        
        //relation
            //galleys are called above, under format
        foreach ($galleys as $galley) {
            $articleSourceNode->appendChild($identifiernode = $doc->createElement('dc:relation', $galley->getData('urlPath')));
        }

        //coverage
        if ($coverages = $publication->getData('coverage')) {
            foreach ($coverages as $coveragelocale => $coverage) {
                if ($coverage != '') {
                    $articleSourceNode->appendChild($coveragenode = $doc->createElement('dc:coverage', strip_tags(strip_tags($coverage))));
                    $coveragenode->setAttribute('xml:lang', strip_tags(substr($coveragelocale, 0, 2)));
                }
            }
        }

        //rights
        if (($copyrightHolder = $publication->getData('copyrightHolder', $locale)) && ($copyrightYear = $publication->getData('copyrightYear'))) {
            $articleSourceNode->appendChild($doc->createElement('dc:rights', strip_tags($copyrightHolder . $copyrightYear)));
        }
        if ($licenseURL = $publication->getData('licenseUrl')) {
            $articleSourceNode->appendChild($doc->createElement('dc:rights', strip_tags($licenseURL)));
        }

        return true;
    }

    /**
     * Create and add Manuscript nodes to a submission node.
     *
     * @param \DOMDocument $doc
     * @param \DOMElement $submissionNode
     * @param Submission $submission
     */
    public function addManuscript($doc, $submissionNode, $submission)
    {
        //To-Do: SIP Struktur
        //$filePath = $this->getExportPath();
        
        
        // Get Submission Files
        $submissionfiles = $this->getSubmissionFiles($submission->getId());
        foreach ($submissionfiles as $submissionfile)
        {
            //filter submission files by status?
            
            if ($submissionfile->getData('fileStage')==2)
            {
                //Add Files
                //COAR Vocabulary: 'preprint' see: https://vocabularies.coar-repositories.org/resource_types/c_816b/
                $this->generateFileNode($doc, $submissionfile->getId(), $submissionNode, 'manuscript', 'preprint', "");
            }
        }

    }



    /**
     * Create and add Peer Review nodes to a submission node.
     *
     * @param \DOMDocument $doc
     * @param \DOMElement $submissionNode
     * @param Submission $submission
     */
    public function addPeerReview($doc, $submissionNode, $submission)
    {
         
        $publication = $submission->getCurrentPublication();
        $locale = $publication->getDefaultLocale();
        //Get RR 
        $reviewrounds = $this->getReviewRoundsDB($submission->getId());
        
        foreach ($reviewrounds as $reviewround) {    

            //Create Peer Review Node
            $submissionNode->appendChild($peerReviewNode = $doc->createElement('peer_review'));

            // Create Reviewed Manuscripts
            // -> Review Round -> Review Round Files -> Submission Files -> genre? =13
            $reviewRoundFiles = $this->getReviewRoundFilesDB($reviewround->review_round_id, 13);
            foreach ($reviewRoundFiles as $reviewRoundFile) {
                //Add Files
                //COAR Vocabulary: 'preprint' see: https://vocabularies.coar-repositories.org/resource_types/c_816b/
                $this->generateFileNode($doc, $reviewRoundFile->submission_file_id, $peerReviewNode, 'reviewed_manuscript', 'preprint', "peer_review/");
            }

            //Create Decisions
            //edit_decitions -> review_round_id ->editor_id, date_decided
            $decision = $this->getDecisionDB($reviewround->review_round_id);
            if (!is_null($decision)) {
                $peerReviewNode->appendChild($decisionnode = $doc->createElement('decision', $this->translateRecommendations($decision->decision)));
                //decision -> type
                //COAR Vocabulary: 'peer_review' see: https://vocabularies.coar-repositories.org/resource_types/H9BQ-739P/
                $decisionnode->appendChild($doc->createElement('dc:type', 'peer_review'));
                //decision -> creator
                $reviewer = Repo::user()->get($decision->editor_id);
                $decisionnode->appendChild($doc->createElement('dc:creator', $reviewer->getFullName(false, false, $locale)));
                //decision -> date
                if ($decision->date_decided != null) {
                    $decisionnode->appendChild($doc->createElement('dc:date', date('Y-m-d', strtotime($decision->date_decided))));
                }
            }
            

            //Create Revised Manuscripts
            // -> Review Round -> Review Round Files -> Submission Files -> genre? =15
            $reviewRoundFiles = $this->getReviewRoundFilesDB($reviewround->review_round_id, 15);
            foreach ($reviewRoundFiles as $reviewRoundFile) {
                //Add Files
                //COAR Vocabulary: 'preprint' see: https://vocabularies.coar-repositories.org/resource_types/c_816b/
                $this->generateFileNode($doc, $reviewRoundFile->submission_file_id, $peerReviewNode, 'revised_manuscript', 'preprint', "peer_review/");
            }


            //Extra peer_review nodes for Review Assignments.
            //Get Review Assignments
            $reviewassignments = $this->getReviewAssignmentsDB($reviewround->review_round_id);
            foreach ($reviewassignments as $reviewassignment) {
                //Create Peer Review Node
                $submissionNode->appendChild($peerReviewNode = $doc->createElement('peer_review'));
                
                //Create Reviewed Manuscripts 
                // -> Review Assignment -> Review Files -> Submission Files -> genre? =13
                $reviewAssignmentFiles = $this->getReviewAssignmentFilesDB($reviewassignment->review_id, 13);
                foreach ($reviewAssignmentFiles as $reviewAssignmentFile) {
                    //Add Files
                    //COAR Vocabulary: 'preprint' see: https://vocabularies.coar-repositories.org/resource_types/c_816b/
                    $this->generateFileNode($doc, $reviewAssignmentFile->submission_file_id, $peerReviewNode, 'reviewed_manuscript', 'preprint', "peer_review/");
                }
                // -> Review Round -> Review Round Files -> Submission Files -> genre? =13
                $reviewRoundFiles = $this->getReviewRoundFilesDB($reviewround->review_round_id, 13);
                foreach ($reviewRoundFiles as $reviewRoundFile) {
                    //Add Files
                    //COAR Vocabulary: 'preprint' see: https://vocabularies.coar-repositories.org/resource_types/c_816b/
                    $this->generateFileNode($doc, $reviewRoundFile->submission_file_id, $peerReviewNode, 'reviewed_manuscript', 'preprint', "peer_review/");
                }


                //Create Decision
                $decision = $reviewassignment->recommendation;
                $peerReviewNode->appendChild($decisionnode = $doc->createElement('decision', $this->translateRecommendations($decision)));
                //decision -> type
                //COAR Vocabulary: 'peer_review' see: https://vocabularies.coar-repositories.org/resource_types/H9BQ-739P/
                $decisionnode->appendChild($doc->createElement('dc:type', 'peer_review'));
                //decision -> creator
                $reviewer = Repo::user()->get($reviewassignment->reviewer_id);
                $decisionnode->appendChild($doc->createElement('dc:creator', $reviewer->getFullName(false, false, $locale)));
                //decision -> date
                if ($reviewassignment->date_completed != null) {
                    $decisionnode->appendChild($doc->createElement('dc:date', date('Y-m-d', strtotime($reviewassignment->date_completed))));
                } else {
                    $decisionnode->appendChild($doc->createElement('dc:date', date('Y-m-d', strtotime($reviewassignment->date_assigned))));
                }



                //Create Revised Manuscripts
                
                // -> Review Assignment -> Review Files -> Submission Files -> genre? =15
                $reviewAssignmentFiles = $this->getReviewAssignmentFilesDB($reviewassignment->review_id, 15);
                foreach ($reviewAssignmentFiles as $reviewAssignmentFile) {
                    //Add Files
                    //COAR Vocabulary: 'preprint' see: https://vocabularies.coar-repositories.org/resource_types/c_816b/
                    $this->generateFileNode($doc, $reviewAssignmentFile->submission_file_id, $peerReviewNode, 'revised_manuscript', 'preprint', "peer_review/");
                }
                // -> Review Round -> Review Round Files -> Submission Files -> genre? =15
                $reviewRoundFiles = $this->getReviewRoundFilesDB($reviewround->review_round_id, 15);
                foreach ($reviewRoundFiles as $reviewRoundFile) {
                    //Add Files
                    //COAR Vocabulary: 'preprint' see: https://vocabularies.coar-repositories.org/resource_types/c_816b/
                    $this->generateFileNode($doc, $reviewRoundFile->submission_file_id, $peerReviewNode, 'revised_manuscript', 'preprint', "peer_review/");
                }
                
                //Create Comments 
                //create node for review comments and append to assignment
                $this->generateCommentsNode(
                    $doc, 
                    $this->getSubmissionCommentsDB($reviewassignment->review_id), 
                    $peerReviewNode, 
                    "comment"
                );
            }

        }
        //Create Comments
        //Create Peer Review Node
        $submissionNode->appendChild($peerReviewNode = $doc->createElement('peer_review'));
        //Load Queries
        $discussions = $this->getQueriesDB($submission->getId());
        //Add Submission Comments
        foreach ($discussions as $discussion) {
            $this->generateNotesNode(
                $doc, 
                $this->getNotesDB($discussion->query_id), 
                $peerReviewNode, 
                "comment",
                true
            );
        }
    
    }
    

    /**
     * Create and add Article Source nodes to a submission node.
     *
     * @param \DOMDocument $doc
     * @param \DOMElement $submissionNode
     * @param Submission $submission
     */
    public function addPublicationProof($doc, $submissionNode, $submission)
    {
        //To-Do: SIP Struktur
        $filePath = $this->getExportPath();


        // Get Submission Files
        $submissionfiles = $this->getSubmissionFiles($submission->getId());
        foreach ($submissionfiles as $submissionfile)
        {
            //filter submission files by status?
            
            if ($submissionfile->getData('fileStage')==11 || $submissionfile->getData('fileStage')==9)
            {
                //Add Files
                //COAR Vocabulary: 'preprint' see: https://vocabularies.coar-repositories.org/resource_types/c_816b/
                $this->generateFileNode($doc, $submissionfile->getId(), $submissionNode, 'publication_proof', 'preprint', "");
            }
        }
        
    }

    /**
     * Generate and return a file node that contains a path to the exported file
     * 
     * @param \DOMDocument $doc parent document
     * @param Int $submissionFileId OJS id of the submission file
     * @param \DOMElement $parentNode parent node of the file, to child to.
     * @param String $nodeName Name of the node the file is in
     * @param String $typeString COAR type of the file
     * @param String $pathString Path to the file in SIP
     *
     * @return \DOMElement
     */
    public function generateFileNode($doc, $submissionFileId, $parentNode, $nodeName, $typeString, $pathString)
    {
        $fileManager = new FileManager($submissionFileId);
        $submissionFile = Repo::submissionFile()->get($submissionFileId);
        $locale = $submissionFile->getData('locale');
        

        $parentNode->appendChild($submissionFileNode = $doc->createElement($nodeName));
        //creator
        if ($creators = $submissionFile->getData('creator')){
            foreach ($creators as $creator){
                $submissionFileNode->appendChild($doc->createElement('dc:creator',$creator));
            }
        } else {
            $uploaderUser = Repo::user()->get($submissionFile->getData('uploaderUserId'), true);
            $submissionFileNode->appendChild($doc->createElement('dc:creator',$uploaderUser->getFullName(false, false, $locale)));
        }
        //date
        $submissionFileNode->appendChild($doc->createElement('dc:date', date('Y-m-d', strtotime($submissionFile->getData('createdAt')))));
        //type: add COAR type
        $submissionFileNode->appendChild($typenode = $doc->createElement('dc:type', $typeString));
        $typenode->setAttribute('xml:lang', 'en-US');
        //format: add MIMEtype
        $submissionFileNode->appendChild($doc->createElement('dc:format', strip_tags($submissionFile->getData('mimetype'))));
        //relation

        $submissionFileNode->appendChild($doc->createElement('dc:relation', $pathString . $nodeName . "/" . $submissionFileId . "/" . $submissionFile->getLocalizedData('name')));

        //Export file to specified folder
        //$exportFileName = $this->buildExportFileName($pathString, $submissionFile->getLocalizedData('name'));
        //$fileManager->copyFile($this->getExportpath() . '/' . $submissionFile->getData('path'), $exportFileName);
        
    }

    /**
     * Generate and return a Comments node, containing Review Comments
     * 
     * @param \DOMDocument $doc
     * @param array $comments 
     * @param \DOMElement $parentNode
     * @param String $subNodeName
     * @param Boolean $isNote
     *
     * @return Boolean
     */
    public function generateCommentsNode($doc, $comments, $parentNode, $subNodeName)
    {
        foreach ($comments as $comment) {

            $author = Repo::user()->get($comment->author_id);
            $subNode = $doc->createElement($subNodeName);
            $cDataNode = $doc->createCDATASection($comment->comments);
            $subNode->appendChild($cDataNode);
            if ($comment->comment_title != null && $comment->comment_title != '') {
                $subNode->appendChild($doc->createElement('dc:title', $comment->comment_title));
            }
            $subNode->appendChild($doc->createElement('dc:date', date('Y-m-d', strtotime($comment->date_posted))));
            $subNode->appendChild($doc->createElement('dc:creator', $author->getFullName(false, false)));
            $parentNode->appendChild($subNode);
        }

        return true;
    }

    /**
     * Generate and return a Notes node, containing Notes
     * 
     * @param \DOMDocument $doc
     * @param array $comments 
     * @param \DOMElement $parentNode
     * @param String $subNodeName
     * @param Boolean $isNote
     *
     * @return Boolean
     */
    public function generateNotesNode($doc, $comments, $parentNode, $subNodeName)
    {
        foreach ($comments as $comment) {

            $author = Repo::user()->get($comment->user_id);
            $subNode = $doc->createElement($subNodeName);
            $cDataNode = $doc->createCDATASection($comment->contents);
            $subNode->appendChild($cDataNode);
            $subNode->appendChild($doc->createElement('dc:date', date('Y-m-d', strtotime($comment->date_created))));
            $subNode->appendChild($doc->createElement('dc:creator', $author->getFullName(false, false)));
            $subNode->appendChild($doc->createElement('dc:title', $comment->title));
            $parentNode->appendChild($subNode);
        }
        return true;
    }

    /**
     * Fetch Review Rounds from DB
     * 
     * @param Int $submissionId
     *
     * @return stdClass
     */
    public function getReviewRoundsDB($submissionId)
    {
        return DB::table('review_rounds')->where('submission_id', $submissionId)->get();
    }

    /**
     * Fetch Review Round Files from DB
     * 
     * @param Int $reviewRoundId
     *
     * @return stdClass
     */
    public function getReviewRoundFilesDB($reviewRoundId, $genreId)
    {
        return DB::table('review_round_files')
        ->join('submission_files', 'review_round_files.submission_file_id', '=', 'submission_files.submission_file_id')
        ->where('review_round_files.review_round_id', $reviewRoundId)
        ->where('submission_files.genre_id', $genreId)
        ->select('submission_files.submission_file_id as submission_file_id')
        ->get();
    }

    /**
     * Fetch Review Assignment Files from DB
     * 
     * @param Int $reviewRoundId
     *
     * @return stdClass
     */
    public function getReviewAssignmentFilesDB($reviewId, $genreId)
    {
        return DB::table('review_files')
        ->join('submission_files', 'review_files.submission_file_id', '=', 'submission_files.submission_file_id')
        ->where('review_files.review_id', $reviewId)
        ->where('submission_files.genre_id', $genreId)
        ->select('submission_files.submission_file_id as submission_file_id')
        ->get();
    }


    /**
     * Fetch Submission Comments from DB
     * 
     * @param Int $submissionId
     *
     * @return stdClass
     */
    public function getSubmissionCommentsDB($assocId)
    {
        return DB::table('submission_comments')->where('assoc_id', $assocId)->get();
    }

    /**
     * Fetch Queries from DB
     * 
     * @param Int $submissionId
     *
     * @return stdClass
     */
    public function getQueriesDB($assocId)
    {
        return DB::table('queries')->where('assoc_id', $assocId)->where('assoc_type', PKPApplication::ASSOC_TYPE_SUBMISSION)->get();
    }


    /**
     * Fetch Notes for a single Query from DB
     * 
     * @param Int $queryId
     *
     * @return stdClass
     */
    public function getNotesDB($queryId)
    {
        return DB::table('notes')->where('assoc_id', $queryId)->where('assoc_type', PKPApplication::ASSOC_TYPE_QUERY)->get();
    }

    /**
     * Fetch Review Assignments from DB
     * 
     * @param Int $reviewroundId
     *
     * @return stdClass
     */
    public function getReviewAssignmentsDB($reviewroundID)
    {
        return DB::table('review_assignments')->where('review_round_id', $reviewroundID)->get();
    }

    /**
     * Fetch Issue from DB
     * 
     * @param Int $issue_id
     *
     * @return stdClass
     */
    public function getIssueVolumeYearDB($issueId)
    {
        $issue = DB::table('issues')->where('issue_id', $issueId)->first();
        $year = $issue->year;
        $volume = $issue->volume;
        $prefix = __('issue.vol');
        $resultString = $prefix . " " . $volume . ' (' . $year . ')';

        return $resultString;
    }

    /**
     * Fetch Context Data from DB
     * 
     * @param Int $issueId
     *
     * @return Array
     */
    public function getJournalNameDB($issueId)
    {
        $issue = DB::table('issues')->where('issue_id', $issueId)->first();
        $results = DB::table('journal_settings')
                        ->select('journal_id', 'setting_name', 'setting_value', 'locale')
                        ->where('journal_id', $issue->journal_id)
                        ->where('setting_name', 'name')
                        ->get();

        return $results;
    }

        /**
     * Fetch Context Data from DB
     * 
     * @param Int $issueId
     *
     * @return Array
     */
    public function getIssnDB($issueId)
    {
        $issue = DB::table('issues')->where('issue_id', $issueId)->first();
        $results = DB::table('journal_settings')
                        ->select('journal_id', 'setting_name', 'setting_value', 'locale')
                        ->where([
                            ['journal_id', $issue->journal_id],
                            ['setting_name', 'onlineIssn']
                            ])

                        ->orWhere([
                            ['journal_id', $issue->journal_id],
                            ['setting_name', 'printIssn']
                            ])
                        ->get();

        return $results;
    }

    /**
     * Fetch Decisions from DB
     * 
     * @param Int $review_round_id
     *
     * @return stdClass
     */
    public function getDecisionDB($reviewRoundId)
    {
        return DB::table('edit_decisions')
                    ->where('review_round_id', $reviewRoundId)
                    ->orderBy('date_decided', 'desc')
                    ->first();
    }

    /**
     * Fetch Submission Files from DB
     * 
     * @param Int $review_id
     *
     * @return stdClass
     */
    public function getSubmissionFiles($submissionId)
    {

        $submissionFiles = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->includeDependentFiles()
            ->getMany();

        return $submissionFiles;
    }
    

    public function translateRecommendations($recommendationId)
    {
        $decisions =  [
            Decision::INTERNAL_REVIEW => __('workflow.review.internalReview'),
            Decision::ACCEPT => __('editor.submission.decision.accept'),
            Decision::EXTERNAL_REVIEW => __('editor.submission.decision.sendExternalReview'),
            Decision::PENDING_REVISIONS => __('reviewer.article.decision.pendingRevisions'),
            Decision::RESUBMIT => __('editor.submission.decision.resubmit'),
            Decision::DECLINE => __('editor.submission.decision.decline'),
            Decision::SEND_TO_PRODUCTION => __('editor.submission.decision.sendToProduction'),
            Decision::INITIAL_DECLINE => __('editor.submission.decision.decline'),
            Decision::RECOMMEND_ACCEPT => __('editor.submission.recommend.accept'),
            Decision::RECOMMEND_PENDING_REVISIONS => __('editor.submission.recommend.revisions'),
            Decision::RECOMMEND_RESUBMIT => __('editor.submission.recommend.resubmit'),
            Decision::RECOMMEND_DECLINE => __('editor.submission.recommend.decline'),
            Decision::NEW_EXTERNAL_ROUND => __('editor.submission.decision.newReviewRound'),
            Decision::REVERT_DECLINE => __('editor.submission.decision.revertDecline'),
            Decision::REVERT_INITIAL_DECLINE => __('editor.submission.decision.revertDecline'),
            Decision::SKIP_EXTERNAL_REVIEW => __('editor.submission.decision.skipReview'),
            Decision::BACK_FROM_PRODUCTION => __('editor.submission.decision.backToCopyediting'),
            Decision::BACK_FROM_COPYEDITING => __('editor.submission.decision.backFromCopyediting'),
            Decision::CANCEL_REVIEW_ROUND => __('editor.submission.decision.cancelReviewRound')
        ];

        if (array_key_exists($recommendationId, $decisions)) {
            return $decisions[$recommendationId];
        } else {
            return "";
        }

    }

       //NEW: Check public file path from Config
    /**
     * Return the plugin export directory.
     *
     * @return string The export directory path.
     */
    public function getExportPath()
    {
        return Config::getVar('files', 'files_dir');
    }

    public function cDataNode($doc, $parentNode, $nodeName, $nodeValue)
    {
        $parentNode->appendChild($newNode = $doc->createElement($nodeName));
        $newNode->appendChild($doc->createCDATASection($nodeValue));
        return $newNode;
    }

}
