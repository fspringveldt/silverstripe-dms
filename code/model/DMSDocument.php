<?php

/**
 * @package dms
 *
 * @property Varchar Filename
 * @property Varchar Folder
 * @property Varchar Title
 * @property Text Description
 * @property int ViewCount
 * @property DateTime LastChanged
 * @property Boolean EmbargoedIndefinitely
 * @property Boolean EmbargoedUntilPublished
 * @property DateTime EmbargoedUntilDate
 * @property DateTime ExpireAtDate
 * @property Enum DownloadBehavior
 * @property Enum CanViewType Enum('Anyone, LoggedInUsers, OnlyTheseUsers', 'Anyone')
 * @property Enum CanEditType Enum('LoggedInUsers, OnlyTheseUsers', 'LoggedInUsers')
 *
 * @method ManyManyList RelatedDocuments
 * @method ManyManyList Tags
 * @method ManyManyList ViewerGroups
 * @method ManyManyList EditorGroups
 *
 */
class DMSDocument extends DataObject implements DMSDocumentInterface
{
    private static $db = array(
        "Filename" => "Varchar(255)", // eg. 3469~2011-energysaving-report.pdf
        "Folder" => "Varchar(255)",    // eg.	0
        "Title" => 'Varchar(1024)', // eg. "Energy Saving Report for Year 2011, New Zealand LandCorp"
        "Description" => 'Text',
        "ViewCount" => 'Int',
        // When this document's file was created or last replaced (small changes like updating title don't count)
        "LastChanged" => 'SS_DateTime',

        "EmbargoedIndefinitely" => 'Boolean(false)',
        "EmbargoedUntilPublished" => 'Boolean(false)',
        "EmbargoedUntilDate" => 'SS_DateTime',
        "ExpireAtDate" => 'SS_DateTime',
        "DownloadBehavior" => 'Enum(array("open","download"), "download")',
        "CanViewType" => "Enum('Anyone, LoggedInUsers, OnlyTheseUsers', 'Anyone')",
        "CanEditType" => "Enum('LoggedInUsers, OnlyTheseUsers', 'LoggedInUsers')",
    );

    private static $many_many = array(
        'Pages' => 'SiteTree',
        'RelatedDocuments' => 'DMSDocument',
        'Tags' => 'DMSTag',
        'ViewerGroups' => 'Group',
        'EditorGroups' => 'Group',
    );

    private static $many_many_extraFields = array(
        'Pages' => array(
            'DocumentSort' => 'Int'
        )
    );

    private static $display_fields = array(
        'ID' => 'ID',
        'Title' => 'Title',
        'FilenameWithoutID' => 'Filename',
        'LastChanged' => 'LastChanged'
    );

    private static $singular_name = 'Document';

    private static $plural_name = 'Documents';

    private static $searchable_fields = array(
        'ID' => array(
            'filter' => 'ExactMatchFilter',
            'field' => 'NumericField'
        ),
        'Title',
        'Filename',
        'LastChanged'
    );

    private static $summary_fields = array(
        'Filename' => 'Filename',
        'Title' => 'Title',
        'ViewCount' => 'ViewCount',
        'getPages.count' => 'Page Use'
    );

    /**
     * @var string download|open
     * @config
     */
    private static $default_download_behaviour = 'download';

    public function canView($member = null)
    {
        if (!$member || !(is_a($member, 'Member')) || is_numeric($member)) {
            $member = Member::currentUser();
        }

        // extended access checks
        $results = $this->extend('canView', $member);

        if ($results && is_array($results)) {
            if (!min($results)) {
                return false;
            }
        }

        if (!$this->CanViewType || $this->CanViewType == 'Anyone') {
            return true;
        }

        if ($member && Permission::checkMember($member,
                array(
                    'ADMIN',
                    'SITETREE_EDIT_ALL',
                    'SITETREE_VIEW_ALL',
                )
            )
        ) {
            return true;
        }

        if ($this->isHidden()) {
            return false;
        }

        if ($this->CanViewType == 'LoggedInUsers') {
            return $member && $member->exists();
        }

        if ($this->CanViewType == 'OnlyTheseUsers' && $this->ViewerGroups()->count()) {
            return ($member && $member->inGroups($this->ViewerGroups()));
        }

        return $this->canEdit($member);
    }

    public function canEdit($member = null)
    {
        if (!$member || !(is_a($member, 'Member')) || is_numeric($member)) {
            $member = Member::currentUser();
        }

        $results = $this->extend('canEdit', $member);

        if ($results && is_array($results)) {
            if (!min($results)) {
                return false;
            }
        }

        // Do early admin check
        if ($member && Permission::checkMember($member,
                array(
                    'ADMIN',
                    'SITETREE_EDIT_ALL',
                    'SITETREE_VIEW_ALL',
                )
            )
        ) {
            return true;
        }

        if ($this->CanEditType === 'LoggedInUsers') {
            return $member && $member->exists();
        }

        if ($this->CanEditType === 'OnlyTheseUsers' && $this->EditorGroups()->count()) {
            return $member && $member->inGroups($this->EditorGroups());
        }

        return ($member && Permission::checkMember($member, array('ADMIN', 'SITETREE_EDIT_ALL')));
    }

    /**
     * @param Member $member
     *
     * @return boolean
     */
    public function canCreate($member = null)
    {
        if (!$member || !(is_a($member, 'Member')) || is_numeric($member)) {
            $member = Member::currentUser();
        }

        $results = $this->extend('canCreate', $member);

        if ($results && is_array($results)) {
            if (!min($results)) {
                return false;
            }
        }

        return $this->canEdit($member);
    }

    /**
     * @param Member $member
     *
     * @return boolean
     */
    public function canDelete($member = null)
    {
        if (!$member || !(is_a($member, 'Member')) || is_numeric($member)) {
            $member = Member::currentUser();
        }

        $results = $this->extend('canDelete', $member);

        if ($results && is_array($results)) {
            if (!min($results)) {
                return false;
            }
        }

        return $this->canView();
    }



    /**
     * Associates this document with a Page. This method does nothing if the
     * association already exists.
     *
     * This could be a simple wrapper around $myDoc->Pages()->add($myPage) to
     * add a many_many relation.
     *
     * @param SiteTree $pageObject Page object to associate this Document with
     *
     * @return DMSDocument
     */
    public function addPage($pageObject)
    {
        $this->Pages()->add($pageObject);

        DB::query(
            "UPDATE \"DMSDocument_Pages\" SET \"DocumentSort\"=\"DocumentSort\"+1"
            . " WHERE \"SiteTreeID\" = $pageObject->ID"
        );

        return $this;
    }

    /**
     * Associates this DMSDocument with a set of Pages. This method loops
     * through a set of page ids, and then associates this DMSDocument with the
     * individual Page with the each page id in the set.
     *
     * @param array $pageIDs
     *
     * @return DMSDocument
     */
    public function addPages($pageIDs)
    {
        foreach ($pageIDs as $id) {
            $pageObject = DataObject::get_by_id("SiteTree", $id);

            if ($pageObject && $pageObject->exists()) {
                $this->addPage($pageObject);
            }
        }

        return $this;
    }

    /**
     * Removes the association between this Document and a Page. This method
     * does nothing if the association does not exist.
     *
     * @param SiteTree $pageObject Page object to remove the association to
     *
     * @return DMSDocument
     */
    public function removePage($pageObject)
    {
        $this->Pages()->remove($pageObject);

        return $this;
    }

    /**
     * @see getPages()
     *
     * @return DataList
     */
    public function Pages()
    {
        $pages = $this->getManyManyComponents('Pages');
        $this->extend('updatePages', $pages);

        return $pages;
    }

    /**
     * Returns a list of the Page objects associated with this Document.
     *
     * @return DataList
     */
    public function getPages()
    {
        return $this->Pages();
    }

    /**
     * Removes all associated Pages from the DMSDocument
     *
     * @return DMSDocument
     */
    public function removeAllPages()
    {
        $this->Pages()->removeAll();

        return $this;
    }

    /**
     * Increase ViewCount by 1, without update any other record fields such as
     * LastEdited.
     *
     * @return DMSDocument
     */
    public function trackView()
    {
        if ($this->ID > 0) {
            $count = $this->ViewCount + 1;

            $this->ViewCount = $count;

            DB::query("UPDATE \"DMSDocument\" SET \"ViewCount\"='$count' WHERE \"ID\"={$this->ID}");
        }

        return $this;
    }


    /**
     * Adds a metadata tag to the Document. The tag has a category and a value.
     *
     * Each category can have multiple values by default. So:
     * addTag("fruit","banana") addTag("fruit", "apple") will add two items.
     *
     * However, if the third parameter $multiValue is set to 'false', then all
     * updates to a category only ever update a single value. So:
     * addTag("fruit","banana") addTag("fruit", "apple") would result in a
     * single metadata tag: fruit->apple.
     *
     * Can could be implemented as a key/value store table (although it is more
     * like category/value, because the same category can occur multiple times)
     *
     * @param string $category of a metadata category to add (required)
     * @param string $value of a metadata value to add (required)
     * @param bool $multiValue Boolean that determines if the category is
     *                  multi-value or single-value (optional)
     *
     * @return DMSDocument
     */
    public function addTag($category, $value, $multiValue = true)
    {
        if ($multiValue) {
            //check for a duplicate tag, don't add the duplicate
            $currentTag = $this->Tags()->filter(array('Category' => $category, 'Value' => $value));
            if ($currentTag->Count() == 0) {
                //multi value tag
                $tag = new DMSTag();
                $tag->Category = $category;
                $tag->Value = $value;
                $tag->MultiValue = true;
                $tag->write();
                $tag->Documents()->add($this);
            } else {
                //add the relation between the tag and document
                foreach ($currentTag as $tagObj) {
                    $tagObj->Documents()->add($this);
                }
            }
        } else {
            //single value tag
            $currentTag = $this->Tags()->filter(array('Category' => $category));
            $tag = null;
            if ($currentTag->Count() == 0) {
                //create the single-value tag
                $tag = new DMSTag();
                $tag->Category = $category;
                $tag->Value = $value;
                $tag->MultiValue = false;
                $tag->write();
            } else {
                //update the single value tag
                $tag = $currentTag->first();
                $tag->Value = $value;
                $tag->MultiValue = false;
                $tag->write();
            }

            // regardless of whether we created a new tag or are just updating an
            // existing one, add the relation
            $tag->Documents()->add($this);
        }

        return $this;
    }

    /**
     * @param string $category
     * @param string $value
     *
     * @return DataList
     */
    protected function getTagsObjects($category, $value = null)
    {
        $valueFilter = array("Category" => $category);
        if (!empty($value)) {
            $valueFilter['Value'] = $value;
        }

        $tags = $this->Tags()->filter($valueFilter);
        return $tags;
    }

    /**
     * Fetches all tags associated with this DMSDocument within a given
     * category. If a value is specified this method tries to fetch that
     * specific tag.
     *
     * @param string $category metadata category to get
     * @param string $value value of the tag to get
     *
     * @return array Strings of all the tags or null if there is no match found
     */
    public function getTagsList($category, $value = null)
    {
        $tags = $this->getTagsObjects($category, $value);

        $returnArray = null;

        if ($tags->Count() > 0) {
            $returnArray = array();

            foreach ($tags as $t) {
                $returnArray[] = $t->Value;
            }
        }

        return $returnArray;
    }

    /**
     * Removes a tag from the Document. If you only set a category, then all
     * values in that category are deleted.
     *
     * If you specify both a category and a value, then only that single
     * category/value pair is deleted.
     *
     * Nothing happens if the category or the value do not exist.
     *
     * @param string $category Category to remove
     * @param string $value Value to remove
     *
     * @return DMSDocument
     */
    public function removeTag($category, $value = null)
    {
        $tags = $this->getTagsObjects($category, $value);

        if ($tags->Count() > 0) {
            foreach ($tags as $t) {
                $documentList = $t->Documents();

                //remove the relation between the tag and the document
                $documentList->remove($this);

                //delete the entire tag if it has no relations left
                if ($documentList->Count() == 0) {
                    $t->delete();
                }
            }
        }

        return $this;
    }

    /**
     * Deletes all tags associated with this Document.
     *
     * @return DMSDocument
     */
    public function removeAllTags()
    {
        $allTags = $this->Tags();

        foreach ($allTags as $tag) {
            $documentlist = $tag->Documents();
            $documentlist->remove($this);
            if ($tag->Documents()->Count() == 0) {
                $tag->delete();
            }
        }

        return $this;
    }

    /**
     * Returns a link to download this document from the DMS store.
     * Alternatively a basic javascript alert will be shown should the user not have view permissions. An extension
     * point for this was also added.
     *
     * To extend use the following from within an Extension subclass:
     *
     * <code>
     * public function updateGetLink($result)
     * {
     *     // Do something here
     * }
     * </code>
     *
     * @return string
     */
    public function getLink()
    {
        $result = Controller::join_links(Director::baseURL(), 'dmsdocument/' . $this->ID);
        if (!$this->canView()) {
            $result = sprintf("javascript:alert('%s')", $this->getPermissionDeniedReason());
        }

        $this->extend('updateGetLink', $result);

        return $result;
    }

    /**
     * @return string
     */
    public function Link()
    {
        return $this->getLink();
    }

    /**
     * Hides the document, so it does not show up when getByPage($myPage) is
     * called (without specifying the $showEmbargoed = true parameter).
     *
     * This is similar to expire, except that this method should be used to hide
     * documents that have not yet gone live.
     *
     * @param bool $write Save change to the database
     *
     * @return DMSDocument
     */
    public function embargoIndefinitely($write = true)
    {
        $this->EmbargoedIndefinitely = true;

        if ($write) {
            $this->write();
        }

        return $this;
    }

    /**
     * Hides the document until any page it is linked to is published
     *
     * @param bool $write Save change to database
     *
     * @return DMSDocument
     */
    public function embargoUntilPublished($write = true)
    {
        $this->EmbargoedUntilPublished = true;

        if ($write) {
            $this->write();
        }

        return $this;
    }

    /**
     * Returns if this is Document is embargoed or expired.
     *
     * Also, returns if the document should be displayed on the front-end,
     * respecting the current reading mode of the site and the embargo status.
     *
     * I.e. if a document is embargoed until published, then it should still
     * show up in draft mode.
     *
     * @return bool
     */
    public function isHidden()
    {
        $hidden = $this->isEmbargoed() || $this->isExpired();
        $readingMode = Versioned::get_reading_mode();

        if ($readingMode == "Stage.Stage") {
            if ($this->EmbargoedUntilPublished == true) {
                $hidden = false;
            }
        }

        return $hidden;
    }

    /**
     * Returns if this is Document is embargoed.
     *
     * @return bool
     */
    public function isEmbargoed()
    {
        if (is_object($this->EmbargoedUntilDate)) {
            $this->EmbargoedUntilDate = $this->EmbargoedUntilDate->Value;
        }

        $embargoed = false;

        if ($this->EmbargoedIndefinitely) {
            $embargoed = true;
        } elseif ($this->EmbargoedUntilPublished) {
            $embargoed = true;
        } elseif (!empty($this->EmbargoedUntilDate)) {
            if (SS_Datetime::now()->Value < $this->EmbargoedUntilDate) {
                $embargoed = true;
            }
        }

        return $embargoed;
    }

    /**
     * Hides the document, so it does not show up when getByPage($myPage) is
     * called. Automatically un-hides the Document at a specific date.
     *
     * @param string $datetime date time value when this Document should expire.
     * @param bool $write
     *
     * @return DMSDocument
     */
    public function embargoUntilDate($datetime, $write = true)
    {
        $this->EmbargoedUntilDate = DBField::create_field('SS_Datetime', $datetime)->Format('Y-m-d H:i:s');

        if ($write) {
            $this->write();
        }

        return $this;
    }

    /**
     * Clears any previously set embargos, so the Document always shows up in
     * all queries.
     *
     * @param bool $write
     *
     * @return DMSDocument
     */
    public function clearEmbargo($write = true)
    {
        $this->EmbargoedIndefinitely = false;
        $this->EmbargoedUntilPublished = false;
        $this->EmbargoedUntilDate = null;

        if ($write) {
            $this->write();
        }

        return $this;
    }

    /**
     * Returns if this is Document is expired.
     *
     * @return bool
     */
    public function isExpired()
    {
        if (is_object($this->ExpireAtDate)) {
            $this->ExpireAtDate = $this->ExpireAtDate->Value;
        }

        $expired = false;

        if (!empty($this->ExpireAtDate)) {
            if (SS_Datetime::now()->Value >= $this->ExpireAtDate) {
                $expired = true;
            }
        }

        return $expired;
    }

    /**
     * Hides the document at a specific date, so it does not show up when
     * getByPage($myPage) is called.
     *
     * @param string $datetime date time value when this Document should expire
     * @param bool $write
     *
     * @return DMSDocument
     */
    public function expireAtDate($datetime, $write = true)
    {
        $this->ExpireAtDate = DBField::create_field('SS_Datetime', $datetime)->Format('Y-m-d H:i:s');

        if ($write) {
            $this->write();
        }

        return $this;
    }

    /**
     * Clears any previously set expiry.
     *
     * @param bool $write
     *
     * @return DMSDocument
     */
    public function clearExpiry($write = true)
    {
        $this->ExpireAtDate = null;

        if ($write) {
            $this->write();
        }

        return $this;
    }

    /**
     * Returns a DataList of all previous Versions of this document (check the
     * LastEdited date of each object to find the correct one).
     *
     * If {@link DMSDocument_versions::$enable_versions} is disabled then an
     * Exception is thrown
     *
     * @throws Exception
     *
     * @return DataList List of Document objects
     */
    public function getVersions()
    {
        if (!DMSDocument_versions::$enable_versions) {
            throw new Exception("DMSDocument versions are disabled");
        }

        return DMSDocument_versions::get_versions($this);
    }

    /**
     * Returns the full filename of the document stored in this object.
     *
     * @return string
     */
    public function getFullPath()
    {
        if ($this->Filename) {
            return DMS::get_dms_path() . DIRECTORY_SEPARATOR . $this->Folder . DIRECTORY_SEPARATOR . $this->Filename;
        }

        return null;
    }

    /**
     * Returns the filename of this asset.
     *
     * @return string
     */
    public function getFileName()
    {
        if ($this->getField('Filename')) {
            return $this->getField('Filename');
        } else {
            return ASSETS_DIR . '/';
        }
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->getField('Title');
    }


    /**
     * @return string
     */
    public function getFilenameWithoutID()
    {
        $filenameParts = explode('~', $this->Filename);
        $filename = array_pop($filenameParts);

        return $filename;
    }

    /**
     * @return string
     */
    public function getStorageFolder()
    {
        return DMS::get_dms_path() . DIRECTORY_SEPARATOR . DMS::get_storage_folder($this->ID);
    }

    /**
     * Deletes the DMSDocument, its underlying file, as well as any tags related
     * to this DMSDocument. Also calls the parent DataObject's delete method in
     * order to complete an cascade.
     *
     * @return void
     */
    public function delete()
    {
        // remove tags
        $this->removeAllTags();

        // delete the file (and previous versions of files)
        $filesToDelete = array();
        $storageFolder = $this->getStorageFolder();

        if (file_exists($storageFolder)) {
            if ($handle = opendir($storageFolder)) {
                while (false !== ($entry = readdir($handle))) {
                    // only delete if filename starts the the relevant ID
                    if (strpos($entry, $this->ID.'~') === 0) {
                        $filesToDelete[] = $entry;
                    }
                }

                closedir($handle);

                //delete all this files that have the id of this document
                foreach ($filesToDelete as $file) {
                    $filePath = $storageFolder .DIRECTORY_SEPARATOR . $file;

                    if (is_file($filePath)) {
                        unlink($filePath);
                    }
                }
            }
        }

        $this->removeAllPages();

        // get rid of any versions have saved for this DMSDocument, too
        if (DMSDocument_versions::$enable_versions) {
            $versions = $this->getVersions();

            if ($versions->Count() > 0) {
                foreach ($versions as $v) {
                    $v->delete();
                }
            }
        }

        parent::delete();
    }



    /**
     * Relate an existing file on the filesystem to the document.
     *
     * Copies the file to the new destination, as defined in {@link get_DMS_path()}.
     *
     * @param string $filePath Path to file, relative to webroot.
     *
     * @return DMSDocument
     */
    public function storeDocument($filePath)
    {
        if (empty($this->ID)) {
            user_error("Document must be written to database before it can store documents", E_USER_ERROR);
        }

        // calculate all the path to copy the file to
        $fromFilename = basename($filePath);
        $toFilename = $this->ID. '~' . $fromFilename; //add the docID to the start of the Filename
        $toFolder = DMS::get_storage_folder($this->ID);
        $toPath = DMS::get_dms_path() . DIRECTORY_SEPARATOR . $toFolder . DIRECTORY_SEPARATOR . $toFilename;

        DMS::create_storage_folder(DMS::get_dms_path() . DIRECTORY_SEPARATOR . $toFolder);

        //copy the file into place
        $fromPath = BASE_PATH . DIRECTORY_SEPARATOR . $filePath;

        //version the existing file (copy it to a new "very specific" filename
        if (DMSDocument_versions::$enable_versions) {
            DMSDocument_versions::create_version($this);
        } else {    //otherwise delete the old document file
            $oldPath = $this->getFullPath();
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        copy($fromPath, $toPath);   //this will overwrite the existing file (if present)

        //write the filename of the stored document
        $this->Filename = $toFilename;
        $this->Folder = strval($toFolder);

        $extension = pathinfo($this->Filename, PATHINFO_EXTENSION);

        if (empty($this->Title)) {
            // don't overwrite existing document titles
            $this->Title = basename($filePath, '.'.$extension);
        }

        $this->LastChanged = SS_Datetime::now()->Rfc2822();
        $this->write();

        return $this;
    }

    /**
     * Takes a File object or a String (path to a file) and copies it into the
     * DMS, replacing the original document file but keeping the rest of the
     * document unchanged.
     *
     * @param File|string $file path to a file to store
     *
     * @return DMSDocument object that we replaced the file in
     */
    public function replaceDocument($file)
    {
        $filePath = DMS::transform_file_to_file_path($file);
        $doc = $this->storeDocument($filePath); // replace the document

        return $doc;
    }


    /**
     * Return the type of file for the given extension
     * on the current file name.
     *
     * @param string $ext
     *
     * @return string
     */
    public static function get_file_type($ext)
    {
        $types = array(
            'gif' => 'GIF image - good for diagrams',
            'jpg' => 'JPEG image - good for photos',
            'jpeg' => 'JPEG image - good for photos',
            'png' => 'PNG image - good general-purpose format',
            'ico' => 'Icon image',
            'tiff' => 'Tagged image format',
            'doc' => 'Word document',
            'xls' => 'Excel spreadsheet',
            'zip' => 'ZIP compressed file',
            'gz' => 'GZIP compressed file',
            'dmg' => 'Apple disk image',
            'pdf' => 'Adobe Acrobat PDF file',
            'mp3' => 'MP3 audio file',
            'wav' => 'WAV audo file',
            'avi' => 'AVI video file',
            'mpg' => 'MPEG video file',
            'mpeg' => 'MPEG video file',
            'js' => 'Javascript file',
            'css' => 'CSS file',
            'html' => 'HTML file',
            'htm' => 'HTML file'
        );

        return isset($types[$ext]) ? $types[$ext] : $ext;
    }


    /**
     * Returns the Description field with HTML <br> tags added when there is a
     * line break.
     *
     * @return string
     */
    public function getDescriptionWithLineBreak()
    {
        return nl2br($this->getField('Description'));
    }

    /**
     * @return FieldList
     */
    public function getCMSFields()
    {
        //include JS to handling showing and hiding of bottom "action" tabs
        Requirements::javascript(DMS_DIR.'/javascript/DMSDocumentCMSFields.js');
        Requirements::css(DMS_DIR.'/css/DMSDocumentCMSFields.css');

        $fields = new FieldList();  //don't use the automatic scaffolding, it is slow and unnecessary here

        $extraTasks = '';   //additional text to inject into the list of tasks at the bottom of a DMSDocument CMSfield

        //get list of shortcode page relations
        $relationFinder = new ShortCodeRelationFinder();
        $relationList = $relationFinder->getList($this->ID);

        $fieldsTop = $this->getFieldsForFile($relationList->count());
        $fields->add($fieldsTop);

        $fields->add(new TextField('Title', 'Title'));
        $fields->add(new TextareaField('Description', 'Description'));

        $downloadBehaviorSource = array(
            'open' => _t('DMSDocument.OPENINBROWSER', 'Open in browser'),
            'download' => _t('DMSDocument.FORCEDOWNLOAD', 'Force download'),
        );
        $defaultDownloadBehaviour = Config::inst()->get('DMSDocument', 'default_download_behaviour');
        if (!isset($downloadBehaviorSource[$defaultDownloadBehaviour])) {
            user_error('Default download behaviour "' . $defaultDownloadBehaviour . '" not supported.', E_USER_WARNING);
        } else {
            $downloadBehaviorSource[$defaultDownloadBehaviour] .= ' (' . _t('DMSDocument.DEFAULT', 'default') . ')';
        }

        $fields->add(
            OptionsetField::create(
                'DownloadBehavior',
                _t('DMSDocument.DOWNLOADBEHAVIOUR', 'Download behavior'),
                $downloadBehaviorSource,
                $defaultDownloadBehaviour
            )
            ->setDescription(
                'How the visitor will view this file. <strong>Open in browser</strong> '
                . 'allows files to be opened in a new tab.'
            )
        );

        //create upload field to replace document
        $uploadField = new DMSUploadField('ReplaceFile', 'Replace file');
        $uploadField->setConfig('allowedMaxFileNumber', 1);
        $uploadField->setConfig('downloadTemplateName', 'ss-dmsuploadfield-downloadtemplate');
        $uploadField->setRecord($this);

        $gridFieldConfig = GridFieldConfig::create()->addComponents(
            new GridFieldToolbarHeader(),
            new GridFieldSortableHeader(),
            new GridFieldDataColumns(),
            new GridFieldPaginator(30),
            //new GridFieldEditButton(),
            new GridFieldDetailForm()
        );

        $gridFieldConfig->getComponentByType('GridFieldDataColumns')
            ->setDisplayFields(array(
                'Title'=>'Title',
                'ClassName'=>'Page Type',
                'ID'=>'Page ID'
            ))
            ->setFieldFormatting(array(
                'Title'=>sprintf(
                    '<a class=\"cms-panel-link\" href=\"%s/$ID\">$Title</a>',
                    singleton('CMSPageEditController')->Link('show')
                )
            ));

        $pagesGrid = GridField::create(
            'Pages',
            _t('DMSDocument.RelatedPages', 'Related Pages'),
            $this->Pages(),
            $gridFieldConfig
        );

        $referencesGrid = GridField::create(
            'References',
            _t('DMSDocument.RelatedReferences', 'Related References'),
            $relationList,
            $gridFieldConfig
        );

        if (DMSDocument_versions::$enable_versions) {
            $versionsGridFieldConfig = GridFieldConfig::create()->addComponents(
                new GridFieldToolbarHeader(),
                new GridFieldSortableHeader(),
                new GridFieldDataColumns(),
                new GridFieldPaginator(30)
            );
            $versionsGridFieldConfig->getComponentByType('GridFieldDataColumns')
                ->setDisplayFields(Config::inst()->get('DMSDocument_versions', 'display_fields'))
                ->setFieldCasting(array('LastChanged'=>"Datetime->Ago"))
                ->setFieldFormatting(
                    array(
                        'FilenameWithoutID' => '<a target=\'_blank\' class=\'file-url\' href=\'$Link\'>'
                            . '$FilenameWithoutID</a>'
                    )
                );

            $versionsGrid =  GridField::create(
                'Versions',
                _t('DMSDocument.Versions', 'Versions'),
                $this->getVersions(),
                $versionsGridFieldConfig
            );
            $extraTasks .= '<li class="ss-ui-button" data-panel="find-versions">Versions</li>';
        }

        $fields->add(new LiteralField(
            'BottomTaskSelection',
            '<div id="Actions" class="field actions"><label class="left">Actions</label><ul>'
            . '<li class="ss-ui-button" data-panel="embargo">Embargo</li>'
            . '<li class="ss-ui-button" data-panel="expiry">Expiry</li>'
            . '<li class="ss-ui-button" data-panel="replace">Replace</li>'
            . '<li class="ss-ui-button" data-panel="find-usage">Usage</li>'
            . '<li class="ss-ui-button" data-panel="find-references">References</li>'
            . '<li class="ss-ui-button" data-panel="find-relateddocuments">Related Documents</li>'
            . $extraTasks
            . '</ul></div>'
        ));

        $embargoValue = 'None';
        if ($this->EmbargoedIndefinitely) {
            $embargoValue = 'Indefinitely';
        } elseif ($this->EmbargoedUntilPublished) {
            $embargoValue = 'Published';
        } elseif (!empty($this->EmbargoedUntilDate)) {
            $embargoValue = 'Date';
        }
        $embargo = new OptionsetField(
            'Embargo',
            'Embargo',
            array(
                'None' => 'None',
                'Published' => 'Hide document until page is published',
                'Indefinitely' => 'Hide document indefinitely',
                'Date' => 'Hide until set date'
            ),
            $embargoValue
        );
        $embargoDatetime = DatetimeField::create('EmbargoedUntilDate', '');
        $embargoDatetime->getDateField()
            ->setConfig('showcalendar', true)
            ->setConfig('dateformat', 'dd-MM-yyyy')
            ->setConfig('datavalueformat', 'dd-MM-yyyy');

        $expiryValue = 'None';
        if (!empty($this->ExpireAtDate)) {
            $expiryValue = 'Date';
        }
        $expiry = new OptionsetField(
            'Expiry',
            'Expiry',
            array(
                'None' => 'None',
                'Date' => 'Set document to expire on'
            ),
            $expiryValue
        );
        $expiryDatetime = DatetimeField::create('ExpireAtDate', '');
        $expiryDatetime->getDateField()
            ->setConfig('showcalendar', true)
            ->setConfig('dateformat', 'dd-MM-yyyy')
            ->setConfig('datavalueformat', 'dd-MM-yyyy');

        // This adds all the actions details into a group.
        // Embargo, History, etc to go in here
        // These are toggled on and off via the Actions Buttons above
        // exit('hit');
        $actionsPanel = FieldGroup::create(
            FieldGroup::create($embargo, $embargoDatetime)->addExtraClass('embargo'),
            FieldGroup::create($expiry, $expiryDatetime)->addExtraClass('expiry'),
            FieldGroup::create($uploadField)->addExtraClass('replace'),
            FieldGroup::create($pagesGrid)->addExtraClass('find-usage'),
            FieldGroup::create($referencesGrid)->addExtraClass('find-references'),
            FieldGroup::create($versionsGrid)->addExtraClass('find-versions'),
            FieldGroup::create($this->getRelatedDocumentsGridField())->addExtraClass('find-relateddocuments')
        );

        $actionsPanel->setName("ActionsPanel");
        $actionsPanel->addExtraClass("DMSDocumentActionsPanel");
        $fields->push($actionsPanel);

        $this->addPermissionsFields($fields);
        $this->extend('updateCMSFields', $fields);

        return $fields;
    }

    /**
     * Adds permissions selection fields to the FieldList.
     *
     * @param FieldList $fields
     */
    public function addPermissionsFields($fields)
    {
        $showFields = array(
            'CanViewType'  => '',
            'ViewerGroups' => 'hide',
            'CanEditType'  => '',
            'EditorGroups' => 'hide',
        );
        /** @var SiteTree $siteTree */
        $siteTree = singleton('SiteTree');
        $settingsFields = $siteTree->getSettingsFields();

        foreach ($showFields as $name => $extraCss) {
            $compositeName = "Root.Settings.$name";
            /** @var FormField $field */
            if ($field = $settingsFields->fieldByName($compositeName)) {
                $field->addExtraClass($extraCss);
                $title = str_replace('page', 'document', $field->Title());
                $field->setTitle($title);

                // Remove Inherited source option from DropdownField
                if ($field instanceof DropdownField) {
                    $options = $field->getSource();
                    unset($options['Inherit']);
                    $field->setSource($options);
                }
                $fields->push($field);
            }
        }

        $this->extend('updatePermissionsFields', $fields);
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        if (isset($this->Embargo)) {
            //set the embargo options from the OptionSetField created in the getCMSFields method
            //do not write after clearing the embargo (write happens automatically)
            $savedDate = $this->EmbargoedUntilDate;
            $this->clearEmbargo(false); //clear all previous settings and re-apply them on save

            if ($this->Embargo == 'Published') {
                $this->embargoUntilPublished(false);
            }
            if ($this->Embargo == 'Indefinitely') {
                $this->embargoIndefinitely(false);
            }
            if ($this->Embargo == 'Date') {
                $this->embargoUntilDate($savedDate, false);
            }
        }

        if (isset($this->Expiry)) {
            if ($this->Expiry == 'Date') {
                $this->expireAtDate($this->ExpireAtDate, false);
            } else {
                $this->clearExpiry(false);
            } //clear all previous settings
        }
    }

    /**
     * Return the relative URL of an icon for the file type, based on the
     * {@link appCategory()} value.
     *
     * Images are searched for in "dms/images/app_icons/".
     *
     * @return string
     */
    public function Icon($ext)
    {
        if (!Director::fileExists(DMS_DIR."/images/app_icons/{$ext}_32.png")) {
            $ext = File::get_app_category($ext);
        }

        if (!Director::fileExists(DMS_DIR."/images/app_icons/{$ext}_32.png")) {
            $ext = "generic";
        }

        return DMS_DIR."/images/app_icons/{$ext}_32.png";
    }

    /**
     * Return the extension of the file associated with the document
     *
     * @return string
     */
    public function getExtension()
    {
        return strtolower(pathinfo($this->Filename, PATHINFO_EXTENSION));
    }

    /**
     * @return string
     */
    public function getSize()
    {
        $size = $this->getAbsoluteSize();
        return ($size) ? File::format_size($size) : false;
    }

    /**
     * Return the size of the file associated with the document.
     *
     * @return string
     */
    public function getAbsoluteSize()
    {
        return file_exists($this->getFullPath()) ? filesize($this->getFullPath()) : null;
    }

    /**
     * An alias to DMSDocument::getSize()
     *
     * @return string
     */
    public function getFileSizeFormatted()
    {
        return $this->getSize();
    }


    /**
     * @return FieldList
     */
    protected function getFieldsForFile($relationListCount)
    {
        $extension = $this->getExtension();

        $previewField = new LiteralField(
            "ImageFull",
            "<img id='thumbnailImage' class='thumbnail-preview' src='{$this->Icon($extension)}?r="
            . rand(1, 100000) . "' alt='{$this->Title}' />\n"
        );

        //count the number of pages this document is published on
        $publishedOnCount = $this->Pages()->Count();
        $publishedOnValue = "$publishedOnCount pages";
        if ($publishedOnCount == 1) {
            $publishedOnValue = "$publishedOnCount page";
        }

        $relationListCountValue = "$relationListCount pages";
        if ($relationListCount == 1) {
            $relationListCountValue = "$relationListCount page";
        }

        $fields = new FieldGroup(
            $filePreview = CompositeField::create(
                CompositeField::create(
                    $previewField
                )->setName("FilePreviewImage")->addExtraClass('cms-file-info-preview'),
                CompositeField::create(
                    CompositeField::create(
                        new ReadonlyField("ID", "ID number". ':', $this->ID),
                        new ReadonlyField(
                            "FileType",
                            _t('AssetTableField.TYPE', 'File type') . ':',
                            self::get_file_type($extension)
                        ),
                        new ReadonlyField(
                            "Size",
                            _t('AssetTableField.SIZE', 'File size') . ':',
                            $this->getFileSizeFormatted()
                        ),
                        $urlField = new ReadonlyField(
                            'ClickableURL',
                            _t('AssetTableField.URL', 'URL'),
                            sprintf(
                                '<a href="%s" target="_blank" class="file-url">%s</a>',
                                $this->getLink(),
                                $this->getLink()
                            )
                        ),
                        new ReadonlyField("FilenameWithoutIDField", "Filename". ':', $this->getFilenameWithoutID()),
                        new DateField_Disabled(
                            "Created",
                            _t('AssetTableField.CREATED', 'First uploaded') . ':',
                            $this->Created
                        ),
                        new DateField_Disabled(
                            "LastEdited",
                            _t('AssetTableField.LASTEDIT', 'Last changed') . ':',
                            $this->LastEdited
                        ),
                        new DateField_Disabled(
                            "LastChanged",
                            _t('AssetTableField.LASTCHANGED', 'Last replaced') . ':',
                            $this->LastChanged
                        ),
                        new ReadonlyField("PublishedOn", "Published on". ':', $publishedOnValue),
                        new ReadonlyField("ReferencedOn", "Referenced on". ':', $relationListCountValue),
                        new ReadonlyField("ViewCount", "View count". ':', $this->ViewCount)
                    )
                )->setName("FilePreviewData")->addExtraClass('cms-file-info-data')
            )->setName("FilePreview")->addExtraClass('cms-file-info')
        );

        $fields->setName('FileP');
        $urlField->dontEscape = true;

        return $fields;
    }

    /**
     * Takes a file and adds it to the DMSDocument storage, replacing the
     * current file.
     *
     * @param File $file
     *
     * @return $this
     */
    public function ingestFile($file)
    {
        $this->replaceDocument($file);
        $file->delete();

        return $this;
    }

    /**
     * Get a data list of documents related to this document
     *
     * @return DataList
     */
    public function getRelatedDocuments()
    {
        $documents = $this->RelatedDocuments();

        $this->extend('updateRelatedDocuments', $documents);

        return $documents;
    }

    /**
     * Get a GridField for managing related documents
     *
     * @return GridField
     */
    protected function getRelatedDocumentsGridField()
    {
        $gridField = GridField::create(
            'RelatedDocuments',
            _t('DMSDocument.RELATEDDOCUMENTS', 'Related Documents'),
            $this->RelatedDocuments(),
            new GridFieldConfig_RelationEditor
        );

        $gridField->getConfig()->removeComponentsByType('GridFieldAddNewButton');
        // Move the autocompleter to the left
        $gridField->getConfig()->removeComponentsByType('GridFieldAddExistingAutocompleter');
        $gridField->getConfig()->addComponent(new GridFieldAddExistingAutocompleter('buttons-before-left'));

        $this->extend('updateRelatedDocumentsGridField', $gridField);

        return $gridField;
    }

    /**
     * Checks at least one group is selected if CanViewType || CanEditType == 'OnlyTheseUsers'
     *
     * @return ValidationResult
     */
    protected function validate()
    {
        $valid = parent::validate();

        if ($this->CanViewType == 'OnlyTheseUsers' && !$this->ViewerGroups()->count()) {
            $valid->error(
                _t(
                    'DMSDocument.VALIDATIONERROR_NOVIEWERSELECTED',
                    "Selecting 'Only these people' from a viewers list needs at least one group selected."
                )
            );
        }

        if ($this->CanEditType == 'OnlyTheseUsers' && !$this->EditorGroups()->count()) {
            $valid->error(
                _t(
                    'DMSDocument.VALIDATIONERROR_NOEDITORSELECTED',
                    "Selecting 'Only these people' from a editors list needs at least one group selected."
                )
            );
        }

        return $valid;
    }

    /**
     * Returns a reason as to why this document cannot be viewed.
     *
     * @return string
     */
    public function getPermissionDeniedReason()
    {
        $result = '';

        if ($this->CanViewType == 'LoggedInUsers') {
            $result = _t('DMSDocument.PERMISSIONDENIEDREASON_LOGINREQUIRED', 'Please log in to view this document');
        }

        if ($this->CanViewType == 'OnlyTheseUsers') {
            $result = _t('DMSDocument.PERMISSIONDENIEDREASON_NOTAUTHORISED',
                'You are not authorised to view this document');
        }

        return $result;
    }
}
