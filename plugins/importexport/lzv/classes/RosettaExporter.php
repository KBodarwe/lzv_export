<?php

/**
 * @file plugins/importexport/lzv/filter/ArticleLZVXmlFilter.php
 *
 *
 * @class ArticleLZVXmlFilter
 *
 * @brief Class that converts a Article to a PubMed XML document.
 */
namespace APP\plugins\importexport\lzv\classes;

use DOMDocument;
use APP\plugins\importexport\lzv\LZVExportPlugin;
use APP\facades\Repo;
use PKP\file\FileManager;
use PKP\config\Config;


class RosettaExporter
{
    public function construct()
    {
        
    }

    public function generateSIP($submission, $context, $overwrite = false, $test = false)
    {
        $plugin = new LZVExportPlugin();
        $fileManager = new FileManager($submission);
        //Hier regeln:
        //Ordnerstruktur und Dateiexport

        $basepath = $plugin->getExportpath();
        $acronym = $context->getAcronym($context->getPrimaryLocale());
        $filesdir = Config::getVar('files', 'files_dir');

        if ($test) {
            $path = $basepath . $acronym . '-test-' . $submission->getData('id');
        } else {
            $path = $basepath . $acronym . '-' . $submission->getData('id');
        }
        

        if (file_exists($path.'/content/mets.xml') && !$overwrite) {
            return false;
        }

        if (!is_dir($path)) {
            mkdir($path.'/content/streams', 0777, true);
        } else {
            $this->delTree($path);
            mkdir($path.'/content/streams', 0777, true);
        }

        //Dateiexport

        //Galleys
        $galleys = $submission->getCurrentPublication()->getData('galleys');
        foreach ($galleys as $galley) {
            if (!is_null($galley->getData('submissionFileId'))) {
                $submissionFile = Repo::submissionFile()->get($galley->getData('submissionFileId'));
                $exportFileName = $path . '/content/streams/' . $submissionFile->getLocalizedData('name');
                $fileManager->copyFile($filesdir . '/' . $submissionFile->getData('path'), $exportFileName);
            }
        }


        // Get Submission Files
        $submissionfiles = $this->getSubmissionFiles($submission->getId());

        //manuscript
        if (!is_dir($path.'/content/streams/manuscript')) {
            mkdir($path.'/content/streams/manuscript', 0777, true);
        }

        foreach ($submissionfiles as $submissionfile)
        {
            if ($submissionfile->getData('fileStage')==2) //manuscript
            {
                //export "manuscript"
                $exportFileName = $path . '/content/streams/manuscript/' . $submissionfile->getData('id') . "/" . $submissionfile->getLocalizedData('name');
                $fileManager->copyFile($filesdir . '/' . $submissionfile->getData('path'), $exportFileName);
            }
        }

        //peer_review
        if (!is_dir($path.'/content/streams/peer_review')) {
            mkdir($path.'/content/streams/peer_review', 0777, true);
        }

        $peerReviewFiles = $this->collectPeerReviewFiles($submission);
        foreach ($peerReviewFiles as $peerReviewFile) {
            //export "peer_review"
            $exportFileName = $path . '/content/streams/peer_review/' . $peerReviewFile->getData('id') . "/" . $peerReviewFile->getLocalizedData('name');
            $fileManager->copyFile($filesdir . '/' . $peerReviewFile->getData('path'), $exportFileName);
        }

        //publication_proof
        if (!is_dir($path.'/content/streams/publication_proof')) {
            mkdir($path.'/content/streams/publication_proof', 0777, true);
        }

        foreach ($submissionfiles as $submissionfile)
        {
            if ($submissionfile->getData('fileStage')==11 || $submissionfile->getData('fileStage')==9) //publication_proof
            {
                //export "publication_proof"
                $exportFileName = $path . '/content/streams/publication_proof/' . $submissionfile->getData('id') . "/" . $submissionfile->getLocalizedData('name');
                $fileManager->copyFile($filesdir . '/' . $submissionfile->getData('path'), $exportFileName);
            }
        }


        //Generierung METS
        $this->generateMETS($submission, $path.'/content');
        
        //PublicationProcessExport aufrufen, XML abspeichern
        $this->generatePublicationProcess($submission, $path.'/content/streams');

        return true;
    }

    public function generatePublicationProcess($submission, $path)
    {
        $exporter = new PublicationProcessExport();
        $plugin = new LZVExportPlugin();

        libxml_use_internal_errors(true);

        $submissionXml = $exporter->export($submission);
        $xml = $submissionXml->saveXml();

        //erzeugtes XML im korrekten Ordner ablegen -> Ordnerstruktur
        $filepath = $path . "/publication_process.xml";
        file_put_contents($filepath, $xml);

        $errors = array_filter(libxml_get_errors(), function ($a) {
            return $a->level == LIBXML_ERR_ERROR || $a->level == LIBXML_ERR_FATAL;
        });
        libxml_clear_errors();

        if (!empty($errors)) {
            $plugin->displayXMLValidationErrors($errors, $xml);
        }

        return $xml;
    }

    public function generateMETS($submission, $path)
    {
        $plugin = new LZVExportPlugin();

        libxml_use_internal_errors(true);

        $metsXml = $this->generateMETSXml($submission);
        $xml = $metsXml->saveXml();

        //erzeugtes XML im korrekten Ordner ablegen -> Ordnerstruktur
        $filepath = $path . "/mets.xml";
        file_put_contents($filepath, $xml);

        $errors = array_filter(libxml_get_errors(), function ($a) {
            return $a->level == LIBXML_ERR_ERROR || $a->level == LIBXML_ERR_FATAL;
        });
        libxml_clear_errors();

        if (!empty($errors)) {
            $plugin->displayXMLValidationErrors($errors, $xml);
        }

        return $xml;
    }

    public function generateMETSXml($submission)
    {
        //Hier regeln:
        // Create the XML document
        $doc = new DOMDocument('1.0');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        $rootNode = $this->createMetsNode($doc, $submission);

        $doc->appendChild($rootNode);
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:mets', 'http://www.loc.gov/METS/');
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dcterms', 'http://purl.org/dc/terms/');
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dnx', 'http://www.exlibrisgroup.com/dps/dnx');
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xlin', 'http://www.w3.org/1999/xlink');
        //$rootNode->setAttribute('xsi:schemaLocation', '\APP\plugins\importexport\lzv\schema\mets_rosetta.xsd');

        return $doc;
    }

    public function createMetsNode($doc, $submission)
    {
        // Create the root node
        $metsNode = $doc->createElement('mets:mets');
        
        //add dmdSec
        $this->createDmdSecNode($doc, $submission, $metsNode);
        //add amdSec
        $this->createAmdSecNodes($doc, $submission, $metsNode);
        //add fileSec
        $this->createFileSecNodes($doc, $submission, $metsNode);
        //add structMap
        $this->createStructMapNode($doc, $submission, $metsNode);

        return $metsNode;
    }

    public function createDmdSecNode($doc, $submission, $metsNode)
    {
        //create dmdSec Node
        $metsNode->appendChild($dmdSecNode = $doc->createElement('mets:dmdSec'));
        //TO-DO: Find out how ID works
        $dmdSecNode->setAttribute('ID', 'ie-dmd');

        //create mdWrap node
        $dmdSecNode->appendChild($mdWrapNode = $doc->createElement('mets:mdWrap'));
        $mdWrapNode->setAttribute('MDTYPE', 'DC');

        //create xmlData node
        $mdWrapNode->appendChild($xmlDataNode = $doc->createElement('mets:xmlData'));

        //load DC Terms
        $exporter = new PublicationProcessExport();
        $publication = $submission->getCurrentPublication();
        $xmlDataNode->appendChild($dcrecordNode = $doc->createElement('dc:record'));
        $dcrecordNode->setAttribute('xmlns:dc', 'http://purl.org/dc/elements/1.1/');
        $dcrecordNode->setAttribute('xmlns:dcterms', 'http://purl.org/dc/terms/');
        $dcrecordNode->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $exporter->addDcTerms($doc, $dcrecordNode, $publication);
        
    }

    public function createAmdSecNodes($doc, $submission, $metsNode)
    {
        // ie-amd
        //create amdSec Node
        $metsNode->appendChild($amdSecNode = $doc->createElement('mets:amdSec'));
        $amdSecNode->setAttribute('ID', 'ie-amd');

            //create techMD node
            $amdSecNode->appendChild($techMdNode = $doc->createElement('mets:techMD'));
            $techMdNode->setAttribute('ID', 'ie-amd-tech');
                //create mdWrap node
                $techMdNode->appendChild($mdWrapNode = $doc->createElement('mets:mdWrap'));
                $mdWrapNode->setAttribute('MDTYPE', 'OTHER');
                $mdWrapNode->setAttribute('OTHERMDTYPE', 'dnx');
                    //create xmlData node
                    $mdWrapNode->appendChild($xmlDataNode = $doc->createElement('mets:xmlData'));
                        //create dnx node
                        $xmlDataNode->appendChild($dnxNode = $doc->createElement('dnx'));
                        $dnxNode->setAttribute('xmlns', 'http://www.exlibrisgroup.com/dps/dnx');
                            //create generalIECharacteristics section node
                            $dnxNode->appendChild($section1Node = $doc->createElement('section'));
                            $section1Node->setAttribute('id', 'generalIECharacteristics');
                                //create record node
                                $section1Node->appendChild($record1Node = $doc->createElement('record'));
                                    //create key nodes
                                    $record1Node->appendChild($key1Node = $doc->createElement('key', 'LZV Bayern'));
                                    $key1Node->setAttribute('id', 'submissionReason');
                                    $record1Node->appendChild($key2Node = $doc->createElement('key', 'ACTIVE'));
                                    $key2Node->setAttribute('id', 'status');
                                    //To-Do: Klären ob hier anderes Label richtiger ist als Dataset
                                    $record1Node->appendChild($key3Node = $doc->createElement('key', 'Monograph'));
                                    $key3Node->setAttribute('id', 'IEEntityType');
                            //create objectIdentifier section node
                            $dnxNode->appendChild($section2Node = $doc->createElement('section'));
                            $section2Node->setAttribute('id', 'objectIdentifier');
                                //create record node
                                $section2Node->appendChild($record2Node = $doc->createElement('record'));
                                    //create key nodes
                                    $record2Node->appendChild($key4Node = $doc->createElement('key', 'OJS-Export'));
                                    $key4Node->setAttribute('id', 'objectIdentifierType');
                                    //Dataset Key: Use DOI of publication if available. Else use ID.
                                    $objectIdentifierKey = $this->generateObjectIdentifierKey($submission);
                                    $record2Node->appendChild($key5Node = $doc->createElement('key', $objectIdentifierKey));
                                    $key5Node->setAttribute('id', 'objectIdentifierValue');


            //create empty rightsMD, sourceMD and digiprovMD node
            $this->createEmptyNodes($doc, $amdSecNode, 'ie-amd');
        
        
        
        // rep1-amd
        //create amdSec Node
        $metsNode->appendChild($amdSec1Node = $doc->createElement('mets:amdSec'));
        $amdSec1Node->setAttribute('ID', 'rep1-amd');

            //create techMD node
            $amdSec1Node->appendChild($techMd1Node = $doc->createElement('mets:techMD'));
            $techMd1Node->setAttribute('ID', 'rep1-amd-tech');
                //create mdWrap node
                $techMd1Node->appendChild($mdWrap1Node = $doc->createElement('mets:mdWrap'));
                $mdWrap1Node->setAttribute('MDTYPE', 'OTHER');
                $mdWrap1Node->setAttribute('OTHERMDTYPE', 'dnx');
                    //create xmlData node
                    $mdWrap1Node->appendChild($xmlData1Node = $doc->createElement('mets:xmlData'));
                        //create dnx node
                        $xmlData1Node->appendChild($dnx1Node = $doc->createElement('dnx'));
                        $dnx1Node->setAttribute('xmlns', 'http://www.exlibrisgroup.com/dps/dnx');
                            //create generalRepCharacteristics section node
                            $dnx1Node->appendChild($section3Node = $doc->createElement('section'));
                            $section3Node->setAttribute('id', 'generalRepCharacteristics');
                                //create record node
                                $section3Node->appendChild($record3Node = $doc->createElement('record'));
                                    //create key nodes
                                    $record3Node->appendChild($key6Node = $doc->createElement('key', 'PRESERVATION_MASTER'));
                                    $key6Node->setAttribute('id', 'preservationType');
                                    $record3Node->appendChild($key7Node = $doc->createElement('key', 'VIEW'));
                                    $key7Node->setAttribute('id', 'usageType');
                                    $record3Node->appendChild($key8Node = $doc->createElement('key', 'true'));
                                    $key8Node->setAttribute('id', 'DigitalOriginal');
                                    $record3Node->appendChild($key9Node = $doc->createElement('key', '1'));
                                    $key9Node->setAttribute('id', 'RevisionNumber');



            //create empty rightsMD, sourceMD and digiprovMD node
            $this->createEmptyNodes($doc, $amdSec1Node, 'rep1-amd');



        // all other amdSec

        //Galleys
        $this->createGalleyNodes($doc, $submission, $metsNode);
        
        //publicationprocess.xml
        $this->createPublicationProcessNode($doc, $metsNode);

        //the rest

        
        // Get Submission Files
        $submissionfiles = $this->getSubmissionFiles($submission->getId());
        foreach ($submissionfiles as $submissionfile)
        {
            if ($submissionfile->getData('fileStage')==2) //manuscript
            {
                //generate node for "manuscript"
                $this->createSubmissionFileAmdNode($doc, $metsNode, $submissionfile, 'manuscript');
            }
        }
        $peerReviewFiles = $this->collectPeerReviewFiles($submission);
        foreach ($peerReviewFiles as $peerReviewFile) {
            //generate node for "peer_review"
            $this->createSubmissionFileAmdNode($doc, $metsNode, $peerReviewFile, 'peer_review');
        }
        foreach ($submissionfiles as $submissionfile)
        {
            if ($submissionfile->getData('fileStage')==11 || $submissionfile->getData('fileStage')==9) //publication_proof
            {
                //generate node for "publication_proof"
                $this->createSubmissionFileAmdNode($doc, $metsNode, $submissionfile, 'publication_proof');
            }
        }

    }

    public function createFileSecNodes($doc, $submission, $metsNode)
    {
        //create fileSec Node
        $metsNode->appendChild($fileSecNode = $doc->createElement('mets:fileSec'));
        $fileSecNode->appendChild($fileGrpNode = $doc->createElement('mets:fileGrp'));
        $fileGrpNode->setAttribute('ID', 'rep1');

        
        //Galleys
        $galleys = $submission->getCurrentPublication()->getData('galleys');
        $galleynumber = 0;
        foreach ($galleys as $galley) {
            if(!is_null($galley->getData('submissionFileId'))) {
                $submissionFile = Repo::submissionFile()->get($galley->getData('submissionFileId'));
                if ($galleynumber > 0) {
                    $galleyID = 'galley' . $galleynumber;
                } else {
                    $galleyID = 'galley';
                }
                $this->createFileNode($doc, $fileGrpNode, $galleyID, $submissionFile->getLocalizedData('name'));
                $galleynumber++;
            }
            
        }
        
        
        //publicationprocess.xml
        $this->createFileNode($doc, $fileGrpNode, 'publication_process', 'publication_process.xml');

        //the rest
        // Get Submission Files
        $submissionfiles = $this->getSubmissionFiles($submission->getId());
        foreach ($submissionfiles as $submissionfile)
        {
            if ($submissionfile->getData('fileStage')==2) //manuscript
            {
                //generate node for "manuscript"
                $this->createFileNode($doc, $fileGrpNode, 'manuscript-'.$submissionfile->getData('id'), 'manuscript/'.$submissionfile->getData('id')."/".$submissionfile->getLocalizedData('name'));
            }
        }
        $peerReviewFiles = $this->collectPeerReviewFiles($submission);
        foreach ($peerReviewFiles as $peerReviewFile) {
            //generate node for "peer_review"
            $this->createFileNode($doc, $fileGrpNode, 'peer_review-'.$peerReviewFile->getData('id'), 'peer_review/'.$peerReviewFile->getData('id')."/".$peerReviewFile->getLocalizedData('name'));
        }
        foreach ($submissionfiles as $submissionfile)
        {
            if ($submissionfile->getData('fileStage')==11 || $submissionfile->getData('fileStage')==9) //publication_proof
            {
                //generate node for "publication_proof"
                $this->createFileNode($doc, $fileGrpNode, 'publication_proof-'.$submissionfile->getData('id'), 'publication_proof/'.$submissionfile->getData('id')."/".$submissionfile->getLocalizedData('name'));
            }
        }

    }

    public function createStructMapNode($doc, $submission, $metsNode) {
        //create structMap Node
        $metsNode->appendChild($structMapNode = $doc->createElement('mets:structMap'));
        $structMapNode->setAttribute('ID', 'structmap-1');
        $structMapNode->setAttribute('TYPE', 'LOGICAL');

        $structMapNode->appendChild($preservationMasterNode = $doc->createElement('mets:div'));
        $preservationMasterNode->setAttribute('LABEL', 'PRESERVATION_MASTER');

            $preservationMasterNode->appendChild($tableOfContentNode = $doc->createElement('mets:div'));
            $tableOfContentNode->setAttribute('LABEL', 'Table of Contents');

            //Galleys
            $galleys = $submission->getCurrentPublication()->getData('galleys');
            $galleynumber = 0;
            foreach ($galleys as $galley) {
                if (!is_null($galley->getData('submissionFileId'))) {
                    $submissionFile = Repo::submissionFile()->get($galley->getData('submissionFileId'));
                    if ($galleynumber > 0) {
                        $galleyID = 'galley' . $galleynumber;
                    } else {
                        $galleyID = 'galley';
                    }
                    $this->createStructMapFileNode($doc, $tableOfContentNode, $submissionFile->getLocalizedData('name'), $galleyID);
                    $galleynumber++;
                }
            }

            //publicationprocess.xml
            $this->createStructMapFileNode($doc, $tableOfContentNode, 'publication_process.xml', 'publication_process');
            
            //the rest
            // Get Submission Files
            $submissionfiles = $this->getSubmissionFiles($submission->getId());

            //manuscript
            $tableOfContentNode->appendChild($manuscriptNode = $doc->createElement('mets:div'));
            $manuscriptNode->setAttribute('LABEL', 'manuscript');

            foreach ($submissionfiles as $submissionfile)
            {
                if ($submissionfile->getData('fileStage')==2) //manuscript
                {
                    //generate node for "manuscript"
                    $this->createStructMapSubmissionFileNode($doc, $manuscriptNode, $submissionfile, 'manuscript');
                }
            }

            //peer_review
            $tableOfContentNode->appendChild($peerReviewNode = $doc->createElement('mets:div'));
            $peerReviewNode->setAttribute('LABEL', 'peer_review');

            $peerReviewFiles = $this->collectPeerReviewFiles($submission);
            foreach ($peerReviewFiles as $peerReviewFile) {
                //generate node for "peer_review"
                $this->createStructMapSubmissionFileNode($doc, $peerReviewNode, $peerReviewFile, 'peer_review');
            }

            //publication_proof
            $tableOfContentNode->appendChild($publicationProofNode = $doc->createElement('mets:div'));
            $publicationProofNode->setAttribute('LABEL', 'publication_proof');

            foreach ($submissionfiles as $submissionfile)
            {
                if ($submissionfile->getData('fileStage')==11 || $submissionfile->getData('fileStage')==9) //publication_proof
                {
                    //generate node for "publication_proof"
                    $this->createStructMapSubmissionFileNode($doc, $publicationProofNode, $submissionfile, 'publication_proof');
                }
            }
            
    }

    public function createStructMapFileNode($doc, $parentNode, $label, $fileId) {
        $parentNode->appendChild($fileDiv = $doc->createElement('mets:div'));
        $fileDiv->setAttribute('LABEL', $label);
        $fileDiv->setAttribute('TYPE', 'FILE');
        $fileDiv->appendChild($fptr = $doc->createElement('mets:fptr'));
        $fptr->setAttribute('FILEID', $fileId);
    }

    public function createStructMapSubmissionFileNode($doc, $parentNode, $submissionFile, $basepath) {
        $parentNode->appendChild($folderDiv = $doc->createElement('mets:div'));
        $folderDiv->setAttribute('LABEL', $submissionFile->getData('id'));
        $folderDiv->appendChild($fileDiv = $doc->createElement('mets:div'));
        $fileDiv->setAttribute('LABEL', $submissionFile->getLocalizedData('name'));
        $fileDiv->setAttribute('TYPE', 'FILE');
        $fileDiv->appendChild($fptr = $doc->createElement('mets:fptr'));
        $fptr->setAttribute('FILEID', $basepath . '-' . $submissionFile->getData('id'));
    }


    public function createEmptyMdWrapNode($doc, $parentNode)
    {
        //create mdWrap node
        $parentNode->appendChild($mdWrap1Node = $doc->createElement('mets:mdWrap'));
        $mdWrap1Node->setAttribute('MDTYPE', 'OTHER');
        $mdWrap1Node->setAttribute('OTHERMDTYPE', 'dnx');
            //create xmlData node
            $mdWrap1Node->appendChild($xmlData1Node = $doc->createElement('mets:xmlData'));
                //create dnx node
                $xmlData1Node->appendChild($dnx1Node = $doc->createElement('dnx'));
                $dnx1Node->setAttribute('xmlns', 'http://www.exlibrisgroup.com/dps/dnx');
    }

    public function createEmptyNodes($doc, $parentNode, $nodeId) 
    {
        //create rightsMD node
        $parentNode->appendChild($rightsMd1Node = $doc->createElement('mets:techMD'));
        $rightsMd1Node->setAttribute('ID', $nodeId.'-rights');
        $this->createEmptyMdWrapNode($doc, $rightsMd1Node);
            
        //create sourceMD node
        $parentNode->appendChild($sourceMd1Node = $doc->createElement('mets:techMD'));
        $sourceMd1Node->setAttribute('ID', $nodeId.'-source');
        $this->createEmptyMdWrapNode($doc, $sourceMd1Node);

        //create digiprovMD node
        $parentNode->appendChild($digiprovMd1Node = $doc->createElement('mets:techMD'));
        $digiprovMd1Node->setAttribute('ID', $nodeId.'-digiprov');
        $this->createEmptyMdWrapNode($doc, $digiprovMd1Node);
    }

    public function generateObjectIdentifierKey($submission) {
        $publication = $submission->getCurrentPublication();
            if ($publication->getStoredPubId('doi')) {
                $objectIdentifierKey = $publication->getStoredPubId('doi');
                return $objectIdentifierKey;
            } else {
                $objectIdentifierKey = $submission->getData('id');
                return $objectIdentifierKey;
            }
    }

    public function createGalleyNodes($doc, $submission, $metsNode) {
        $galleys = $submission->getCurrentPublication()->getData('galleys');
        $galleynumber = 0;
        foreach ($galleys as $galley) {
            if(!is_null($galley->getData('submissionFileId'))) {
                $submissionFile = Repo::submissionFile()->get($galley->getData('submissionFileId'));
            if ($galleynumber > 0) {
                $galleyID = 'galley' . $galleynumber;
            } else {
                $galleyID = 'galley';
            }
            //create amdSec Node
            $metsNode->appendChild($amdSecGalleyNode = $doc->createElement('mets:amdSec'));
            $amdSecGalleyNode->setAttribute('ID', $galleyID.'-amd');

                //create techMD node
                $amdSecGalleyNode->appendChild($techMdGalleyNode = $doc->createElement('mets:techMD'));
                $techMdGalleyNode->setAttribute('ID', $galleyID.'-amd-tech');
                    //create mdWrap node
                    $techMdGalleyNode->appendChild($mdWrapGalleyNode = $doc->createElement('mets:mdWrap'));
                    $mdWrapGalleyNode->setAttribute('MDTYPE', 'OTHER');
                    $mdWrapGalleyNode->setAttribute('OTHERMDTYPE', 'dnx');
                        //create xmlData node
                        $mdWrapGalleyNode->appendChild($xmlDataNode = $doc->createElement('mets:xmlData'));
                            //create dnx node
                            $xmlDataNode->appendChild($dnxGalleyNode = $doc->createElement('dnx'));
                            $dnxGalleyNode->setAttribute('xmlns', 'http://www.exlibrisgroup.com/dps/dnx');
                                //create generalFileCharacteristics section node
                                $dnxGalleyNode->appendChild($section4Node = $doc->createElement('section'));
                                $section4Node->setAttribute('id', 'generalFileCharacteristics');
                                    //create record node
                                    $section4Node->appendChild($recordGalleyNode = $doc->createElement('record'));
                                        //create key nodes
                                        $recordGalleyNode->appendChild($key10Node = $doc->createElement('key', ''));
                                        $key10Node->setAttribute('id', 'label');
                                        $recordGalleyNode->appendChild($key11Node = $doc->createElement('key', ''));
                                        $key11Node->setAttribute('id', 'note');
                                        $recordGalleyNode->appendChild($key12Node = $doc->createElement('key', $submissionFile->getLocalizedData('name')));
                                        $key12Node->setAttribute('id', 'fileOriginalName');
                                        $recordGalleyNode->appendChild($key13Node = $doc->createElement('key', $submissionFile->getLocalizedData('name')));
                                        $key13Node->setAttribute('id', 'fileOriginalPath');
                                        $recordGalleyNode->appendChild($key14Node = $doc->createElement('key', ''));
                                        $key14Node->setAttribute('id', 'fileSizeBytes');
                                        $recordGalleyNode->appendChild($key15Node = $doc->createElement('key', date('Y-m-d', strtotime($submissionFile->getData('createdAt')))));
                                        $key15Node->setAttribute('id', 'fileCreationDate');
                                        $recordGalleyNode->appendChild($key16Node = $doc->createElement('key', date('Y-m-d', strtotime($submissionFile->getData('updatedAt')))));
                                        $key16Node->setAttribute('id', 'fileModificationDate');



                //create empty rightsMD, sourceMD and digiprovMD node
                $this->createEmptyNodes($doc, $amdSecGalleyNode, $galleyID.'-amd');
                $galleynumber++;

            } 
        }
    }

    public function createPublicationProcessNode($doc, $metsNode) {
        //create amdSec Node
        $metsNode->appendChild($amdSecGalleyNode = $doc->createElement('mets:amdSec'));
        $amdSecGalleyNode->setAttribute('ID', 'publication_process-amd');

            //create techMD node
            $amdSecGalleyNode->appendChild($techMdGalleyNode = $doc->createElement('mets:techMD'));
            $techMdGalleyNode->setAttribute('ID', 'publication_process-amd-tech');
                //create mdWrap node
                $techMdGalleyNode->appendChild($mdWrapGalleyNode = $doc->createElement('mets:mdWrap'));
                $mdWrapGalleyNode->setAttribute('MDTYPE', 'OTHER');
                $mdWrapGalleyNode->setAttribute('OTHERMDTYPE', 'dnx');
                    //create xmlData node
                    $mdWrapGalleyNode->appendChild($xmlDataNode = $doc->createElement('mets:xmlData'));
                        //create dnx node
                        $xmlDataNode->appendChild($dnxGalleyNode = $doc->createElement('dnx'));
                        $dnxGalleyNode->setAttribute('xmlns', 'http://www.exlibrisgroup.com/dps/dnx');
                            //create generalFileCharacteristics section node
                            $dnxGalleyNode->appendChild($section4Node = $doc->createElement('section'));
                            $section4Node->setAttribute('id', 'generalFileCharacteristics');
                                //create record node
                                $section4Node->appendChild($recordGalleyNode = $doc->createElement('record'));
                                    //create key nodes
                                    $recordGalleyNode->appendChild($key10Node = $doc->createElement('key', ''));
                                    $key10Node->setAttribute('id', 'label');
                                    $recordGalleyNode->appendChild($key11Node = $doc->createElement('key', ''));
                                    $key11Node->setAttribute('id', 'note');
                                    $recordGalleyNode->appendChild($key12Node = $doc->createElement('key', 'publication_process.xml'));
                                    $key12Node->setAttribute('id', 'fileOriginalName');
                                    $recordGalleyNode->appendChild($key13Node = $doc->createElement('key', 'publication_process.xml'));
                                    $key13Node->setAttribute('id', 'fileOriginalPath');
                                    $recordGalleyNode->appendChild($key14Node = $doc->createElement('key', ''));
                                    $key14Node->setAttribute('id', 'fileSizeBytes');
                                    $recordGalleyNode->appendChild($key15Node = $doc->createElement('key', date('Y-m-d')));
                                    $key15Node->setAttribute('id', 'fileCreationDate');
                                    $recordGalleyNode->appendChild($key16Node = $doc->createElement('key', date('Y-m-d')));
                                    $key16Node->setAttribute('id', 'fileModificationDate');



            //create empty rightsMD, sourceMD and digiprovMD node
            $this->createEmptyNodes($doc, $amdSecGalleyNode, 'publication_process-amd');
    }

    public function createFileNode($doc, $parentNode, $id, $link)  {
        $parentNode->appendChild($fileNode = $doc->createElement('mets:file'));
        $fileNode->setAttribute('ID', $id);
        $fileNode->appendChild($flocatNode = $doc->createElement('mets:FLocat'));
        $flocatNode->setAttribute('xmlns:xlin', 'http://www.w3.org/1999/xlink');
        $flocatNode->setAttribute('LOCTYPE', 'URL');
        $flocatNode->setAttribute('xlin:href', $link);
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

    public function collectPeerReviewFiles($submission)
    {
        $result = array();
        $exporter = new PublicationProcessExport();
        //Get RR 
        $reviewrounds = $exporter->getReviewRoundsDB($submission->getId());
        
        foreach ($reviewrounds as $reviewround) {    

            // Create Reviewed Manuscripts
            // -> Review Round -> Review Round Files -> Submission Files -> genre? =13
            $reviewRoundFiles = $exporter->getReviewRoundFilesDB($reviewround->review_round_id, 13);
            foreach ($reviewRoundFiles as $reviewRoundFile) {
                $result[] = Repo::submissionFile()->get($reviewRoundFile->submission_file_id);
            }

            //Create Revised Manuscripts
            // -> Review Round -> Review Round Files -> Submission Files -> genre? =15
            $reviewRoundFiles = $exporter->getReviewRoundFilesDB($reviewround->review_round_id, 15);
            foreach ($reviewRoundFiles as $reviewRoundFile) {
                $result[] = Repo::submissionFile()->get($reviewRoundFile->submission_file_id);
            }


            //Extra peer_review nodes for Review Assignments.
            //Get Review Assignments
            $reviewassignments = $exporter->getReviewAssignmentsDB($reviewround->review_round_id);
            foreach ($reviewassignments as $reviewassignment) {
                //Create Peer Review Node
                
                //Create Reviewed Manuscripts 
                // -> Review Assignment -> Review Files -> Submission Files -> genre? =13
                $reviewAssignmentFiles = $exporter->getReviewAssignmentFilesDB($reviewassignment->review_id, 13);
                foreach ($reviewAssignmentFiles as $reviewAssignmentFile) {
                    $result[] = Repo::submissionFile()->get($reviewAssignmentFile->submission_file_id);   
                }
                // -> Review Round -> Review Round Files -> Submission Files -> genre? =13
                $reviewRoundFiles = $exporter->getReviewRoundFilesDB($reviewround->review_round_id, 13);
                foreach ($reviewRoundFiles as $reviewRoundFile) {
                    $result[] = Repo::submissionFile()->get($reviewRoundFile->submission_file_id);
                }

                //Create Revised Manuscripts
                
                // -> Review Assignment -> Review Files -> Submission Files -> genre? =15
                $reviewAssignmentFiles = $exporter->getReviewAssignmentFilesDB($reviewassignment->review_id, 15);
                foreach ($reviewAssignmentFiles as $reviewAssignmentFile) {
                    $result[] = Repo::submissionFile()->get($reviewAssignmentFile->submission_file_id); 
                }
                // -> Review Round -> Review Round Files -> Submission Files -> genre? =15
                $reviewRoundFiles = $exporter->getReviewRoundFilesDB($reviewround->review_round_id, 15);
                foreach ($reviewRoundFiles as $reviewRoundFile) {
                    $result[] = Repo::submissionFile()->get($reviewRoundFile->submission_file_id);
                }
                
            }

        }
        return $result;
    }

    public function createSubmissionFileAmdNode($doc, $metsNode, $submissionFile, $basepath) {
        //load submission File ID
        $submissionFileId = $submissionFile->getData('id');
        //create ID:
        $id = $basepath . "-" . $submissionFileId;
        //create amdSec Node
        $metsNode->appendChild($amdSecGalleyNode = $doc->createElement('mets:amdSec'));
        $amdSecGalleyNode->setAttribute('ID', $id.'-amd');

            //create techMD node
            $amdSecGalleyNode->appendChild($techMdGalleyNode = $doc->createElement('mets:techMD'));
            $techMdGalleyNode->setAttribute('ID', $id.'-amd-tech');
                //create mdWrap node
                $techMdGalleyNode->appendChild($mdWrapGalleyNode = $doc->createElement('mets:mdWrap'));
                $mdWrapGalleyNode->setAttribute('MDTYPE', 'OTHER');
                $mdWrapGalleyNode->setAttribute('OTHERMDTYPE', 'dnx');
                    //create xmlData node
                    $mdWrapGalleyNode->appendChild($xmlDataNode = $doc->createElement('mets:xmlData'));
                        //create dnx node
                        $xmlDataNode->appendChild($dnxGalleyNode = $doc->createElement('dnx'));
                        $dnxGalleyNode->setAttribute('xmlns', 'http://www.exlibrisgroup.com/dps/dnx');
                            //create generalFileCharacteristics section node
                            $dnxGalleyNode->appendChild($section4Node = $doc->createElement('section'));
                            $section4Node->setAttribute('id', 'generalFileCharacteristics');
                                //create record node
                                $section4Node->appendChild($recordGalleyNode = $doc->createElement('record'));
                                    //create key nodes
                                    $recordGalleyNode->appendChild($key10Node = $doc->createElement('key', ''));
                                    $key10Node->setAttribute('id', 'label');
                                    $recordGalleyNode->appendChild($key11Node = $doc->createElement('key', ''));
                                    $key11Node->setAttribute('id', 'note');
                                    $recordGalleyNode->appendChild($key12Node = $doc->createElement('key', $submissionFile->getLocalizedData('name')));
                                    $key12Node->setAttribute('id', 'fileOriginalName');
                                    $recordGalleyNode->appendChild($key13Node = $doc->createElement('key', $basepath . "/" . $submissionFileId . "/" . $submissionFile->getLocalizedData('name')));
                                    $key13Node->setAttribute('id', 'fileOriginalPath');
                                    $recordGalleyNode->appendChild($key14Node = $doc->createElement('key', ''));
                                    $key14Node->setAttribute('id', 'fileSizeBytes');
                                    $recordGalleyNode->appendChild($key15Node = $doc->createElement('key', date('Y-m-d', strtotime($submissionFile->getData('createdAt')))));
                                    $key15Node->setAttribute('id', 'fileCreationDate');
                                    $recordGalleyNode->appendChild($key16Node = $doc->createElement('key', date('Y-m-d', strtotime($submissionFile->getData('updatedAt')))));
                                    $key16Node->setAttribute('id', 'fileModificationDate');



            //create empty rightsMD, sourceMD and digiprovMD node
            $this->createEmptyNodes($doc, $amdSecGalleyNode, $id.'-amd');

    }


    public function delTree($dir) {

        $files = array_diff(scandir($dir), array('.','..'));
     
        foreach ($files as $file) {
     
           (is_dir("$dir/$file")) ? $this->delTree("$dir/$file") : unlink("$dir/$file");
     
        }
     
        return rmdir($dir);
     
    }
}