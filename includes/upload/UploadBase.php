<?php
/**
 * Base class for the backend of file upload.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Upload
 */

/**
 * @defgroup Upload Upload related
 */

/**
 * @ingroup Upload
 *
 * UploadBase and subclasses are the backend of MediaWiki's file uploads.
 * The frontends are formed by ApiUpload and SpecialUpload.
 *
 * See also includes/docs/upload.txt
 *
 * @author Brion Vibber
 * @author Bryan Tong Minh
 * @author Michael Dale
 */
abstract class UploadBase {
	protected $mTempPath;
	protected $mDesiredDestName, $mDestName, $mRemoveTempFile, $mSourceType;
	protected $mTitle = false, $mTitleError = 0;
	protected $mFilteredName, $mFinalExtension;
	protected $mLocalFile, $mFileSize, $mFileProps;
	protected $mBlackListedExtensions;
	protected $mJavaDetected;

	protected static $safeXmlEncodings = array( 'UTF-8', 'ISO-8859-1', 'ISO-8859-2', 'UTF-16', 'UTF-32' );

	const SUCCESS = 0;
	const OK = 0;
	const EMPTY_FILE = 3;
	const MIN_LENGTH_PARTNAME = 4;
	const ILLEGAL_FILENAME = 5;
	const OVERWRITE_EXISTING_FILE = 7; # Not used anymore; handled by verifyTitlePermissions()
	const FILETYPE_MISSING = 8;
	const FILETYPE_BADTYPE = 9;
	const VERIFICATION_ERROR = 10;

	# HOOK_ABORTED is the new name of UPLOAD_VERIFICATION_ERROR
	const UPLOAD_VERIFICATION_ERROR = 11;
	const HOOK_ABORTED = 11;
	const FILE_TOO_LARGE = 12;
	const WINDOWS_NONASCII_FILENAME = 13;
	const FILENAME_TOO_LONG = 14;

	/**
	 * @param $error int
	 * @return string
	 */
	public function getVerificationErrorCode( $error ) {
		$code_to_status = array(self::EMPTY_FILE => 'empty-file',
								self::FILE_TOO_LARGE => 'file-too-large',
								self::FILETYPE_MISSING => 'filetype-missing',
								self::FILETYPE_BADTYPE => 'filetype-banned',
								self::MIN_LENGTH_PARTNAME => 'filename-tooshort',
								self::ILLEGAL_FILENAME => 'illegal-filename',
								self::OVERWRITE_EXISTING_FILE => 'overwrite',
								self::VERIFICATION_ERROR => 'verification-error',
								self::HOOK_ABORTED =>  'hookaborted',
								self::WINDOWS_NONASCII_FILENAME => 'windows-nonascii-filename',
								self::FILENAME_TOO_LONG => 'filename-toolong',
		);
		if( isset( $code_to_status[$error] ) ) {
			return $code_to_status[$error];
		}

		return 'unknown-error';
	}

	/**
	 * Returns true if uploads are enabled.
	 * Can be override by subclasses.
	 * @return bool
	 */
	public static function isEnabled() {
		global $wgEnableUploads;

		if ( !$wgEnableUploads ) {
			return false;
		}

		# Check php's file_uploads setting
		return wfIsHipHop() || wfIniGetBool( 'file_uploads' );
	}

	/**
	 * Returns true if the user can use this upload module or else a string
	 * identifying the missing permission.
	 * Can be overriden by subclasses.
	 *
	 * @param $user User
	 * @return bool
	 */
	public static function isAllowed( $user ) {
		foreach ( array( 'upload', 'edit' ) as $permission ) {
			if ( !$user->isAllowed( $permission ) ) {
				return $permission;
			}
		}
		return true;
	}

	// Upload handlers. Should probably just be a global.
	static $uploadHandlers = array( 'Stash', 'File', 'Url' );

	/**
	 * Create a form of UploadBase depending on wpSourceType and initializes it
	 *
	 * @param $request WebRequest
	 * @param $type
	 * @return null
	 */
	public static function createFromRequest( &$request, $type = null ) {
		$type = $type ? $type : $request->getVal( 'wpSourceType', 'File' );

		if( !$type ) {
			return null;
		}

		// Get the upload class
		$type = ucfirst( $type );

		// Give hooks the chance to handle this request
		$className = null;
		wfRunHooks( 'UploadCreateFromRequest', array( $type, &$className ) );
		if ( is_null( $className ) ) {
			$className = 'UploadFrom' . $type;
			wfDebug( __METHOD__ . ": class name: $className\n" );
			if( !in_array( $type, self::$uploadHandlers ) ) {
				return null;
			}
		}

		// Check whether this upload class is enabled
		if( !call_user_func( array( $className, 'isEnabled' ) ) ) {
			return null;
		}

		// Check whether the request is valid
		if( !call_user_func( array( $className, 'isValidRequest' ), $request ) ) {
			return null;
		}

		$handler = new $className;

		$handler->initializeFromRequest( $request );
		return $handler;
	}

	/**
	 * Check whether a request if valid for this handler
	 * @param $request
	 * @return bool
	 */
	public static function isValidRequest( $request ) {
		return false;
	}

	public function __construct() {}

	/**
	 * Returns the upload type. Should be overridden by child classes
	 *
	 * @since 1.18
	 * @return string
	 */
	public function getSourceType() { return null; }

	/**
	 * Initialize the path information
	 * @param $name string the desired destination name
	 * @param $tempPath string the temporary path
	 * @param $fileSize int the file size
	 * @param $removeTempFile bool (false) remove the temporary file?
	 * @throws MWException
	 */
	public function initializePathInfo( $name, $tempPath, $fileSize, $removeTempFile = false ) {
		$this->mDesiredDestName = $name;
		if ( FileBackend::isStoragePath( $tempPath ) ) {
			throw new MWException( __METHOD__ . " given storage path `$tempPath`." );
		}
		$this->mTempPath = $tempPath;
		$this->mFileSize = $fileSize;
		$this->mRemoveTempFile = $removeTempFile;
	}

	/**
	 * Initialize from a WebRequest. Override this in a subclass.
	 */
	public abstract function initializeFromRequest( &$request );

	/**
	 * Fetch the file. Usually a no-op
	 * @return Status
	 */
	public function fetchFile() {
		return Status::newGood();
	}

	/**
	 * Return true if the file is empty
	 * @return bool
	 */
	public function isEmptyFile() {
		return empty( $this->mFileSize );
	}

	/**
	 * Return the file size
	 * @return integer
	 */
	public function getFileSize() {
		return $this->mFileSize;
	}

	/**
	 * @param $srcPath String: the source path
	 * @return string the real path if it was a virtual URL
	 */
	function getRealPath( $srcPath ) {
		wfProfileIn( __METHOD__ );
		$repo = RepoGroup::singleton()->getLocalRepo();
		if ( $repo->isVirtualUrl( $srcPath ) ) {
			// @TODO: just make uploads work with storage paths
			// UploadFromStash loads files via virtuals URLs
			$tmpFile = $repo->getLocalCopy( $srcPath );
			$tmpFile->bind( $this ); // keep alive with $thumb
			wfProfileOut( __METHOD__ );
			return $tmpFile->getPath();
		}
		wfProfileOut( __METHOD__ );
		return $srcPath;
	}

	/**
	 * Verify whether the upload is sane.
	 * @return mixed self::OK or else an array with error information
	 */
	public function verifyUpload() {
		wfProfileIn( __METHOD__ );

		/**
		 * If there was no filename or a zero size given, give up quick.
		 */
		if( $this->isEmptyFile() ) {
			wfProfileOut( __METHOD__ );
			return array( 'status' => self::EMPTY_FILE );
		}

		/**
		 * Honor $wgMaxUploadSize
		 */
		$maxSize = self::getMaxUploadSize( $this->getSourceType() );
		if( $this->mFileSize > $maxSize ) {
			wfProfileOut( __METHOD__ );
			return array(
				'status' => self::FILE_TOO_LARGE,
				'max' => $maxSize,
			);
		}

		/**
		 * Look at the contents of the file; if we can recognize the
		 * type but it's corrupt or data of the wrong type, we should
		 * probably not accept it.
		 */
		$verification = $this->verifyFile();
		if( $verification !== true ) {
			wfProfileOut( __METHOD__ );
			return array(
				'status' => self::VERIFICATION_ERROR,
				'details' => $verification
			);
		}

		/**
		 * Make sure this file can be created
		 */
		$result = $this->validateName();
		if( $result !== true ) {
			wfProfileOut( __METHOD__ );
			return $result;
		}

		$error = '';
		if( !wfRunHooks( 'UploadVerification',
			array( $this->mDestName, $this->mTempPath, &$error ) ) )
		{
			wfProfileOut( __METHOD__ );
			return array( 'status' => self::HOOK_ABORTED, 'error' => $error );
		}

		wfProfileOut( __METHOD__ );
		return array( 'status' => self::OK );
	}

	/**
	 * Verify that the name is valid and, if necessary, that we can overwrite
	 *
	 * @return mixed true if valid, otherwise and array with 'status'
	 * and other keys
	 **/
	protected function validateName() {
		$nt = $this->getTitle();
		if( is_null( $nt ) ) {
			$result = array( 'status' => $this->mTitleError );
			if( $this->mTitleError == self::ILLEGAL_FILENAME ) {
				$result['filtered'] = $this->mFilteredName;
			}
			if ( $this->mTitleError == self::FILETYPE_BADTYPE ) {
				$result['finalExt'] = $this->mFinalExtension;
				if ( count( $this->mBlackListedExtensions ) ) {
					$result['blacklistedExt'] = $this->mBlackListedExtensions;
				}
			}
			return $result;
		}
		$this->mDestName = $this->getLocalFile()->getName();

		return true;
	}

	/**
	 * Verify the mime type
	 *
	 * @param $mime string representing the mime
	 * @return mixed true if the file is verified, an array otherwise
	 */
	protected function verifyMimeType( $mime ) {
		global $wgVerifyMimeType;
		wfProfileIn( __METHOD__ );
		if ( $wgVerifyMimeType ) {
			wfDebug ( "\n\nmime: <$mime> extension: <{$this->mFinalExtension}>\n\n");
			global $wgMimeTypeBlacklist;
			if ( $this->checkFileExtension( $mime, $wgMimeTypeBlacklist ) ) {
				wfProfileOut( __METHOD__ );
				return array( 'filetype-badmime', $mime );
			}

			# XXX: Missing extension will be caught by validateName() via getTitle()
			if ( $this->mFinalExtension != '' && !$this->verifyExtension( $mime, $this->mFinalExtension ) ) {
				wfProfileOut( __METHOD__ );
				return array( 'filetype-mime-mismatch', $this->mFinalExtension, $mime );
			}

			# Check IE type
			$fp = fopen( $this->mTempPath, 'rb' );
			$chunk = fread( $fp, 256 );
			fclose( $fp );

			$magic = MimeMagic::singleton();
			$extMime = $magic->guessTypesForExtension( $this->mFinalExtension );
			$ieTypes = $magic->getIEMimeTypes( $this->mTempPath, $chunk, $extMime );
			foreach ( $ieTypes as $ieType ) {
				if ( $this->checkFileExtension( $ieType, $wgMimeTypeBlacklist ) ) {
					wfProfileOut( __METHOD__ );
					return array( 'filetype-bad-ie-mime', $ieType );
				}
			}
		}

		wfProfileOut( __METHOD__ );
		return true;
	}

	/**
	 * Verifies that it's ok to include the uploaded file
	 *
	 * @return mixed true of the file is verified, array otherwise.
	 */
	protected function verifyFile() {
		global $wgAllowJavaUploads, $wgDisableUploadScriptChecks;
		wfProfileIn( __METHOD__ );

		# get the title, even though we are doing nothing with it, because
		# we need to populate mFinalExtension
		$this->getTitle();

		$this->mFileProps = FSFile::getPropsFromPath( $this->mTempPath, $this->mFinalExtension );

		# check mime type, if desired
		$mime = $this->mFileProps[ 'file-mime' ];
		$status = $this->verifyMimeType( $mime );
		if ( $status !== true ) {
			wfProfileOut( __METHOD__ );
			return $status;
		}

		# check for htmlish code and javascript
		if ( !$wgDisableUploadScriptChecks ) {
			if( self::detectScript( $this->mTempPath, $mime, $this->mFinalExtension ) ) {
				wfProfileOut( __METHOD__ );
				return array( 'uploadscripted' );
			}
			if( $this->mFinalExtension == 'svg' || $mime == 'image/svg+xml' ) {
				if( $this->detectScriptInSvg( $this->mTempPath ) ) {
					wfProfileOut( __METHOD__ );
					return array( 'uploadscripted' );
				}
			}
		}

		# Check for Java applets, which if uploaded can bypass cross-site
		# restrictions.
		if ( !$wgAllowJavaUploads ) {
			$this->mJavaDetected = false;
			$zipStatus = ZipDirectoryReader::read( $this->mTempPath,
				array( $this, 'zipEntryCallback' ) );
			if ( !$zipStatus->isOK() ) {
				$errors = $zipStatus->getErrorsArray();
				$error = reset( $errors );
				if ( $error[0] !== 'zip-wrong-format' ) {
					wfProfileOut( __METHOD__ );
					return $error;
				}
			}
			if ( $this->mJavaDetected ) {
				wfProfileOut( __METHOD__ );
				return array( 'uploadjava' );
			}
		}

		# Scan the uploaded file for viruses
		$virus = $this->detectVirus( $this->mTempPath );
		if ( $virus ) {
			wfProfileOut( __METHOD__ );
			return array( 'uploadvirus', $virus );
		}

		$handler = MediaHandler::getHandler( $mime );
		if ( $handler ) {
			$handlerStatus = $handler->verifyUpload( $this->mTempPath );
			if ( !$handlerStatus->isOK() ) {
				$errors = $handlerStatus->getErrorsArray();
				wfProfileOut( __METHOD__ );
				return reset( $errors );
			}
		}

		wfRunHooks( 'UploadVerifyFile', array( $this, $mime, &$status ) );
		if ( $status !== true ) {
			wfProfileOut( __METHOD__ );
			return $status;
		}

		wfDebug( __METHOD__ . ": all clear; passing.\n" );
		wfProfileOut( __METHOD__ );
		return true;
	}

	/**
	 * Callback for ZipDirectoryReader to detect Java class files.
	 */
	function zipEntryCallback( $entry ) {
		$names = array( $entry['name'] );

		// If there is a null character, cut off the name at it, because JDK's
		// ZIP_GetEntry() uses strcmp() if the name hashes match. If a file name
		// were constructed which had ".class\0" followed by a string chosen to
		// make the hash collide with the truncated name, that file could be
		// returned in response to a request for the .class file.
		$nullPos = strpos( $entry['name'], "\000" );
		if ( $nullPos !== false ) {
			$names[] = substr( $entry['name'], 0, $nullPos );
		}

		// If there is a trailing slash in the file name, we have to strip it,
		// because that's what ZIP_GetEntry() does.
		if ( preg_grep( '!\.class/?$!', $names ) ) {
			$this->mJavaDetected = true;
		}
	}

	/**
	 * Alias for verifyTitlePermissions. The function was originally 'verifyPermissions'
	 * but that suggests it's checking the user, when it's really checking the title + user combination.
	 * @param $user User object to verify the permissions against
	 * @return mixed An array as returned by getUserPermissionsErrors or true
	 *               in case the user has proper permissions.
	 */
	public function verifyPermissions( $user ) {
		return $this->verifyTitlePermissions( $user );
	}

	/**
	 * Check whether the user can edit, upload and create the image. This
	 * checks only against the current title; if it returns errors, it may
	 * very well be that another title will not give errors. Therefore
	 * isAllowed() should be called as well for generic is-user-blocked or
	 * can-user-upload checking.
	 *
	 * @param $user User object to verify the permissions against
	 * @return mixed An array as returned by getUserPermissionsErrors or true
	 *               in case the user has proper permissions.
	 */
	public function verifyTitlePermissions( $user ) {
		/**
		 * If the image is protected, non-sysop users won't be able
		 * to modify it by uploading a new revision.
		 */
		$nt = $this->getTitle();
		if( is_null( $nt ) ) {
			return true;
		}
		$permErrors = $nt->getUserPermissionsErrors( 'edit', $user );
		$permErrorsUpload = $nt->getUserPermissionsErrors( 'upload', $user );
		if ( !$nt->exists() ) {
			$permErrorsCreate = $nt->getUserPermissionsErrors( 'create', $user );
		} else {
			$permErrorsCreate = array();
		}
		if( $permErrors || $permErrorsUpload || $permErrorsCreate ) {
			$permErrors = array_merge( $permErrors, wfArrayDiff2( $permErrorsUpload, $permErrors ) );
			$permErrors = array_merge( $permErrors, wfArrayDiff2( $permErrorsCreate, $permErrors ) );
			return $permErrors;
		}

		$overwriteError = $this->checkOverwrite( $user );
		if ( $overwriteError !== true ) {
			return array( $overwriteError );
		}

		return true;
	}

	/**
	 * Check for non fatal problems with the file
	 *
	 * @return Array of warnings
	 */
	public function checkWarnings() {
		global $wgLang;
		wfProfileIn( __METHOD__ );

		$warnings = array();

		$localFile = $this->getLocalFile();
		$filename = $localFile->getName();

		/**
		 * Check whether the resulting filename is different from the desired one,
		 * but ignore things like ucfirst() and spaces/underscore things
		 */
		$comparableName = str_replace( ' ', '_', $this->mDesiredDestName );
		$comparableName = Title::capitalize( $comparableName, NS_FILE );

		if( $this->mDesiredDestName != $filename && $comparableName != $filename ) {
			$warnings['badfilename'] = $filename;
		}

		// Check whether the file extension is on the unwanted list
		global $wgCheckFileExtensions, $wgFileExtensions;
		if ( $wgCheckFileExtensions ) {
			if ( !$this->checkFileExtension( $this->mFinalExtension, $wgFileExtensions ) ) {
				$warnings['filetype-unwanted-type'] = array( $this->mFinalExtension,
					$wgLang->commaList( $wgFileExtensions ), count( $wgFileExtensions ) );
			}
		}

		global $wgUploadSizeWarning;
		if ( $wgUploadSizeWarning && ( $this->mFileSize > $wgUploadSizeWarning ) ) {
			$warnings['large-file'] = $wgUploadSizeWarning;
		}

		if ( $this->mFileSize == 0 ) {
			$warnings['emptyfile'] = true;
		}

		$exists = self::getExistsWarning( $localFile );
		if( $exists !== false ) {
			$warnings['exists'] = $exists;
		}

		// Check dupes against existing files
		$hash = FSFile::getSha1Base36FromPath( $this->mTempPath );
		$dupes = RepoGroup::singleton()->findBySha1( $hash );
		$title = $this->getTitle();
		// Remove all matches against self
		foreach ( $dupes as $key => $dupe ) {
			if( $title->equals( $dupe->getTitle() ) ) {
				unset( $dupes[$key] );
			}
		}
		if( $dupes ) {
			$warnings['duplicate'] = $dupes;
		}

		// Check dupes against archives
		$archivedImage = new ArchivedFile( null, 0, "{$hash}.{$this->mFinalExtension}" );
		if ( $archivedImage->getID() > 0 ) {
			$warnings['duplicate-archive'] = $archivedImage->getName();
		}

		wfProfileOut( __METHOD__ );
		return $warnings;
	}

	/**
	 * Really perform the upload. Stores the file in the local repo, watches
	 * if necessary and runs the UploadComplete hook.
	 *
	 * @param $comment
	 * @param $pageText
	 * @param $watch
	 * @param $user User
	 *
	 * @return Status indicating the whether the upload succeeded.
	 */
	public function performUpload( $comment, $pageText, $watch, $user ) {
		wfProfileIn( __METHOD__ );

		$status = $this->getLocalFile()->upload(
			$this->mTempPath,
			$comment,
			$pageText,
			File::DELETE_SOURCE,
			$this->mFileProps,
			false,
			$user
		);

		if( $status->isGood() ) {
			if ( $watch ) {
				$user->addWatch( $this->getLocalFile()->getTitle() );
			}
			wfRunHooks( 'UploadComplete', array( &$this ) );
		}

		wfProfileOut( __METHOD__ );
		return $status;
	}

	/**
	 * Returns the title of the file to be uploaded. Sets mTitleError in case
	 * the name was illegal.
	 *
	 * @return Title The title of the file or null in case the name was illegal
	 */
	public function getTitle() {
		if ( $this->mTitle !== false ) {
			return $this->mTitle;
		}

		/* Assume that if a user specified File:Something.jpg, this is an error
		 * and that the namespace prefix needs to be stripped of.
		 */
		$title = Title::newFromText( $this->mDesiredDestName );
		if ( $title && $title->getNamespace() == NS_FILE ) {
			$this->mFilteredName = $title->getDBkey();
		} else {
			$this->mFilteredName = $this->mDesiredDestName;
		}

		# oi_archive_name is max 255 bytes, which include a timestamp and an
		# exclamation mark, so restrict file name to 240 bytes.
		if ( strlen( $this->mFilteredName ) > 240 ) {
			$this->mTitleError = self::FILENAME_TOO_LONG;
			return $this->mTitle = null;
		}

		/**
		 * Chop off any directories in the given filename. Then
		 * filter out illegal characters, and try to make a legible name
		 * out of it. We'll strip some silently that Title would die on.
		 */
		$this->mFilteredName = wfStripIllegalFilenameChars( $this->mFilteredName );
		/* Normalize to title form before we do any further processing */
		$nt = Title::makeTitleSafe( NS_FILE, $this->mFilteredName );
		if( is_null( $nt ) ) {
			$this->mTitleError = self::ILLEGAL_FILENAME;
			return $this->mTitle = null;
		}
		$this->mFilteredName = $nt->getDBkey();



		/**
		 * We'll want to blacklist against *any* 'extension', and use
		 * only the final one for the whitelist.
		 */
		list( $partname, $ext ) = $this->splitExtensions( $this->mFilteredName );

		if( count( $ext ) ) {
			$this->mFinalExtension = trim( $ext[count( $ext ) - 1] );
		} else {
			$this->mFinalExtension = '';

			# No extension, try guessing one
			$magic = MimeMagic::singleton();
			$mime = $magic->guessMimeType( $this->mTempPath );
			if ( $mime !== 'unknown/unknown' ) {
				# Get a space separated list of extensions
				$extList = $magic->getExtensionsForType( $mime );
				if ( $extList ) {
					# Set the extension to the canonical extension
					$this->mFinalExtension = strtok( $extList, ' ' );

					# Fix up the other variables
					$this->mFilteredName .= ".{$this->mFinalExtension}";
					$nt = Title::makeTitleSafe( NS_FILE, $this->mFilteredName );
					$ext = array( $this->mFinalExtension );
				}
			}

		}

		/* Don't allow users to override the blacklist (check file extension) */
		global $wgCheckFileExtensions, $wgStrictFileExtensions;
		global $wgFileExtensions, $wgFileBlacklist;

		$blackListedExtensions = $this->checkFileExtensionList( $ext, $wgFileBlacklist );

		if ( $this->mFinalExtension == '' ) {
			$this->mTitleError = self::FILETYPE_MISSING;
			return $this->mTitle = null;
		} elseif ( $blackListedExtensions ||
				( $wgCheckFileExtensions && $wgStrictFileExtensions &&
					!$this->checkFileExtensionList( $ext, $wgFileExtensions ) ) ) {
			$this->mBlackListedExtensions = $blackListedExtensions;
			$this->mTitleError = self::FILETYPE_BADTYPE;
			return $this->mTitle = null;
		}

		// Windows may be broken with special characters, see bug XXX
		if ( wfIsWindows() && !preg_match( '/^[\x0-\x7f]*$/', $nt->getText() ) ) {
			$this->mTitleError = self::WINDOWS_NONASCII_FILENAME;
			return $this->mTitle = null;
		}

		# If there was more than one "extension", reassemble the base
		# filename to prevent bogus complaints about length
		if( count( $ext ) > 1 ) {
			for( $i = 0; $i < count( $ext ) - 1; $i++ ) {
				$partname .= '.' . $ext[$i];
			}
		}

		if( strlen( $partname ) < 1 ) {
			$this->mTitleError =  self::MIN_LENGTH_PARTNAME;
			return $this->mTitle = null;
		}

		return $this->mTitle = $nt;
	}

	/**
	 * Return the local file and initializes if necessary.
	 *
	 * @return LocalFile|null
	 */
	public function getLocalFile() {
		if( is_null( $this->mLocalFile ) ) {
			$nt = $this->getTitle();
			$this->mLocalFile = is_null( $nt ) ? null : wfLocalFile( $nt );
		}
		return $this->mLocalFile;
	}

	/**
	 * If the user does not supply all necessary information in the first upload form submission (either by accident or
	 * by design) then we may want to stash the file temporarily, get more information, and publish the file later.
	 *
	 * This method will stash a file in a temporary directory for later processing, and save the necessary descriptive info
	 * into the database.
	 * This method returns the file object, which also has a 'fileKey' property which can be passed through a form or
	 * API request to find this stashed file again.
	 *
	 * @return UploadStashFile stashed file
	 */
	public function stashFile() {
		// was stashSessionFile
		wfProfileIn( __METHOD__ );

		$stash = RepoGroup::singleton()->getLocalRepo()->getUploadStash();
		$file = $stash->stashFile( $this->mTempPath, $this->getSourceType() );
		$this->mLocalFile = $file;

		wfProfileOut( __METHOD__ );
		return $file;
	}

	/**
	 * Stash a file in a temporary directory, returning a key which can be used to find the file again. See stashFile().
	 *
	 * @return String: file key
	 */
	public function stashFileGetKey() {
		return $this->stashFile()->getFileKey();
	}

	/**
	 * alias for stashFileGetKey, for backwards compatibility
	 *
	 * @return String: file key
	 */
	public function stashSession() {
		return $this->stashFileGetKey();
	}

	/**
	 * If we've modified the upload file we need to manually remove it
	 * on exit to clean up.
	 */
	public function cleanupTempFile() {
		if ( $this->mRemoveTempFile && $this->mTempPath && file_exists( $this->mTempPath ) ) {
			wfDebug( __METHOD__ . ": Removing temporary file {$this->mTempPath}\n" );
			unlink( $this->mTempPath );
		}
	}

	public function getTempPath() {
		return $this->mTempPath;
	}

	/**
	 * Split a file into a base name and all dot-delimited 'extensions'
	 * on the end. Some web server configurations will fall back to
	 * earlier pseudo-'extensions' to determine type and execute
	 * scripts, so the blacklist needs to check them all.
	 *
	 * @param $filename string
	 * @return array
	 */
	public static function splitExtensions( $filename ) {
		$bits = explode( '.', $filename );
		$basename = array_shift( $bits );
		return array( $basename, $bits );
	}

	/**
	 * Perform case-insensitive match against a list of file extensions.
	 * Returns true if the extension is in the list.
	 *
	 * @param $ext String
	 * @param $list Array
	 * @return Boolean
	 */
	public static function checkFileExtension( $ext, $list ) {
		return in_array( strtolower( $ext ), $list );
	}

	/**
	 * Perform case-insensitive match against a list of file extensions.
	 * Returns an array of matching extensions.
	 *
	 * @param $ext Array
	 * @param $list Array
	 * @return Boolean
	 */
	public static function checkFileExtensionList( $ext, $list ) {
		return array_intersect( array_map( 'strtolower', $ext ), $list );
	}

	/**
	 * Checks if the mime type of the uploaded file matches the file extension.
	 *
	 * @param $mime String: the mime type of the uploaded file
	 * @param $extension String: the filename extension that the file is to be served with
	 * @return Boolean
	 */
	public static function verifyExtension( $mime, $extension ) {
		$magic = MimeMagic::singleton();

		if ( !$mime || $mime == 'unknown' || $mime == 'unknown/unknown' )
			if ( !$magic->isRecognizableExtension( $extension ) ) {
				wfDebug( __METHOD__ . ": passing file with unknown detected mime type; " .
					"unrecognized extension '$extension', can't verify\n" );
				return true;
			} else {
				wfDebug( __METHOD__ . ": rejecting file with unknown detected mime type; ".
					"recognized extension '$extension', so probably invalid file\n" );
				return false;
			}

		$match = $magic->isMatchingExtension( $extension, $mime );

		if ( $match === null ) {
			wfDebug( __METHOD__ . ": no file extension known for mime type $mime, passing file\n" );
			return true;
		} elseif( $match === true ) {
			wfDebug( __METHOD__ . ": mime type $mime matches extension $extension, passing file\n" );

			#TODO: if it's a bitmap, make sure PHP or ImageMagic resp. can handle it!
			return true;

		} else {
			wfDebug( __METHOD__ . ": mime type $mime mismatches file extension $extension, rejecting file\n" );
			return false;
		}
	}

	/**
	 * Heuristic for detecting files that *could* contain JavaScript instructions or
	 * things that may look like HTML to a browser and are thus
	 * potentially harmful. The present implementation will produce false
	 * positives in some situations.
	 *
	 * @param $file String: pathname to the temporary upload file
	 * @param $mime String: the mime type of the file
	 * @param $extension String: the extension of the file
	 * @return Boolean: true if the file contains something looking like embedded scripts
	 */
	public static function detectScript( $file, $mime, $extension ) {
		global $wgAllowTitlesInSVG;
		wfProfileIn( __METHOD__ );

		# ugly hack: for text files, always look at the entire file.
		# For binary field, just check the first K.

		if( strpos( $mime,'text/' ) === 0 ) {
			$chunk = file_get_contents( $file );
		} else {
			$fp = fopen( $file, 'rb' );
			$chunk = fread( $fp, 1024 );
			fclose( $fp );
		}

		$chunk = strtolower( $chunk );

		if( !$chunk ) {
			wfProfileOut( __METHOD__ );
			return false;
		}

		# decode from UTF-16 if needed (could be used for obfuscation).
		if( substr( $chunk, 0, 2 ) == "\xfe\xff" ) {
			$enc = 'UTF-16BE';
		} elseif( substr( $chunk, 0, 2 ) == "\xff\xfe" ) {
			$enc = 'UTF-16LE';
		} else {
			$enc = null;
		}

		if( $enc ) {
			$chunk = iconv( $enc, "ASCII//IGNORE", $chunk );
		}

		$chunk = trim( $chunk );

		# @todo FIXME: Convert from UTF-16 if necessarry!
		wfDebug( __METHOD__ . ": checking for embedded scripts and HTML stuff\n" );

		# check for HTML doctype
		if ( preg_match( "/<!DOCTYPE *X?HTML/i", $chunk ) ) {
			wfProfileOut( __METHOD__ );
			return true;
		}

		// Some browsers will interpret obscure xml encodings as UTF-8, while
		// PHP/expat will interpret the given encoding in the xml declaration (bug 47304)
		if ( $extension == 'svg' || strpos( $mime, 'image/svg' ) === 0 ) {
			if ( self::checkXMLEncodingMissmatch( $file ) ) {
				wfProfileOut( __METHOD__ );
				return true;
			}
		}

		/**
		 * Internet Explorer for Windows performs some really stupid file type
		 * autodetection which can cause it to interpret valid image files as HTML
		 * and potentially execute JavaScript, creating a cross-site scripting
		 * attack vectors.
		 *
		 * Apple's Safari browser also performs some unsafe file type autodetection
		 * which can cause legitimate files to be interpreted as HTML if the
		 * web server is not correctly configured to send the right content-type
		 * (or if you're really uploading plain text and octet streams!)
		 *
		 * Returns true if IE is likely to mistake the given file for HTML.
		 * Also returns true if Safari would mistake the given file for HTML
		 * when served with a generic content-type.
		 */
		$tags = array(
			'<a href',
			'<body',
			'<head',
			'<html',   #also in safari
			'<img',
			'<pre',
			'<script', #also in safari
			'<table'
		);

		if( !$wgAllowTitlesInSVG && $extension !== 'svg' && $mime !== 'image/svg' ) {
			$tags[] = '<title';
		}

		foreach( $tags as $tag ) {
			if( false !== strpos( $chunk, $tag ) ) {
				wfDebug( __METHOD__ . ": found something that may make it be mistaken for html: $tag\n" );
				wfProfileOut( __METHOD__ );
				return true;
			}
		}

		/*
		 * look for JavaScript
		 */

		# resolve entity-refs to look at attributes. may be harsh on big files... cache result?
		$chunk = Sanitizer::decodeCharReferences( $chunk );

		# look for script-types
		if( preg_match( '!type\s*=\s*[\'"]?\s*(?:\w*/)?(?:ecma|java)!sim', $chunk ) ) {
			wfDebug( __METHOD__ . ": found script types\n" );
			wfProfileOut( __METHOD__ );
			return true;
		}

		# look for html-style script-urls
		if( preg_match( '!(?:href|src|data)\s*=\s*[\'"]?\s*(?:ecma|java)script:!sim', $chunk ) ) {
			wfDebug( __METHOD__ . ": found html-style script urls\n" );
			wfProfileOut( __METHOD__ );
			return true;
		}

		# look for css-style script-urls
		if( preg_match( '!url\s*\(\s*[\'"]?\s*(?:ecma|java)script:!sim', $chunk ) ) {
			wfDebug( __METHOD__ . ": found css-style script urls\n" );
			wfProfileOut( __METHOD__ );
			return true;
		}

		wfDebug( __METHOD__ . ": no scripts found\n" );
		wfProfileOut( __METHOD__ );
		return false;
	}


	/**
	 * Check a whitelist of xml encodings that are known not to be interpreted differently
	 * by the server's xml parser (expat) and some common browsers.
	 *
	 * @param string $file pathname to the temporary upload file
	 * @return Boolean: true if the file contains an encoding that could be misinterpreted
	 */
	public static function checkXMLEncodingMissmatch( $file ) {
		global $wgSVGMetadataCutoff;
		$contents = file_get_contents( $file, false, null, -1, $wgSVGMetadataCutoff );
		$encodingRegex = '!encoding[ \t\n\r]*=[ \t\n\r]*[\'"](.*?)[\'"]!si';

		if ( preg_match( "!<\?xml\b(.*?)\?>!si", $contents, $matches ) ) {
			if ( preg_match( $encodingRegex, $matches[1], $encMatch )
				&& !in_array( strtoupper( $encMatch[1] ), self::$safeXmlEncodings )
			) {
				wfDebug( __METHOD__ . ": Found unsafe XML encoding '{$encMatch[1]}'\n" );
				return true;
			}
		} elseif ( preg_match( "!<\?xml\b!si", $contents ) ) {
			// Start of XML declaration without an end in the first $wgSVGMetadataCutoff
			// bytes. There shouldn't be a legitimate reason for this to happen.
			wfDebug( __METHOD__ . ": Unmatched XML declaration start\n" );
			return true;
		} elseif ( substr( $contents, 0, 4) == "\x4C\x6F\xA7\x94" ) {
			// EBCDIC encoded XML
			wfDebug( __METHOD__ . ": EBCDIC Encoded XML\n" );
			return true;
		}

		// It's possible the file is encoded with multi-byte encoding, so re-encode attempt to
		// detect the encoding in case is specifies an encoding not whitelisted in self::$safeXmlEncodings
		$attemptEncodings = array( 'UTF-16', 'UTF-16BE', 'UTF-32', 'UTF-32BE' );
		foreach ( $attemptEncodings as $encoding ) {
			wfSuppressWarnings();
			$str = iconv( $encoding, 'UTF-8', $contents );
			wfRestoreWarnings();
			if ( $str != '' && preg_match( "!<\?xml\b(.*?)\?>!si", $str, $matches )	) {
				if ( preg_match( $encodingRegex, $matches[1], $encMatch )
					&& !in_array( strtoupper( $encMatch[1] ), self::$safeXmlEncodings )
				) {
					wfDebug( __METHOD__ . ": Found unsafe XML encoding '{$encMatch[1]}'\n" );
					return true;
				}
			} elseif ( $str != '' && preg_match( "!<\?xml\b!si", $str ) ) {
				// Start of XML declaration without an end in the first $wgSVGMetadataCutoff
				// bytes. There shouldn't be a legitimate reason for this to happen.
				wfDebug( __METHOD__ . ": Unmatched XML declaration start\n" );
				return true;
			}
		}

		return false;
	}

	/**
	 * @param $filename string
	 * @return bool
	 */
	protected function detectScriptInSvg( $filename ) {
		$check = new XmlTypeCheck( $filename, array( $this, 'checkSvgScriptCallback' ) );
		return $check->filterMatch;
	}

	/**
	 * @todo Replace this with a whitelist filter!
	 * @param $element string
	 * @param $attribs array
	 * @return bool
	 */
	public function checkSvgScriptCallback( $element, $attribs ) {
		$strippedElement = $this->stripXmlNamespace( $element );

		/*
		 * check for elements that can contain javascript
		 */
		if( $strippedElement == 'script' ) {
			wfDebug( __METHOD__ . ": Found script element '$element' in uploaded file.\n" );
			return true;
		}

		# e.g., <svg xmlns="http://www.w3.org/2000/svg"> <handler xmlns:ev="http://www.w3.org/2001/xml-events" ev:event="load">alert(1)</handler> </svg>
		if( $strippedElement == 'handler' ) {
			wfDebug( __METHOD__ . ": Found scriptable element '$element' in uploaded file.\n" );
			return true;
		}

		# SVG reported in Feb '12 that used xml:stylesheet to generate javascript block
		if( $strippedElement == 'stylesheet' ) {
			wfDebug( __METHOD__ . ": Found scriptable element '$element' in uploaded file.\n" );
			return true;
		}

		foreach( $attribs as $attrib => $value ) {
			$stripped = $this->stripXmlNamespace( $attrib );
			$value = strtolower($value);

			if( substr( $stripped, 0, 2 ) == 'on' ) {
				wfDebug( __METHOD__ . ": Found event-handler attribute '$attrib'='$value' in uploaded file.\n" );
				return true;
			}

			# href with javascript target
			if( $stripped == 'href' && strpos( strtolower( $value ), 'javascript:' ) !== false ) {
				wfDebug( __METHOD__ . ": Found script in href attribute '$attrib'='$value' in uploaded file.\n" );
				return true;
			}

			# href with embeded svg as target
			if( $stripped == 'href' && preg_match( '!data:[^,]*image/svg[^,]*,!sim', $value ) ) {
				wfDebug( __METHOD__ . ": Found href to embedded svg \"<$strippedElement '$attrib'='$value'...\" in uploaded file.\n" );
				return true;
			}

			# href with embeded (text/xml) svg as target
			if( $stripped == 'href' && preg_match( '!data:[^,]*text/xml[^,]*,!sim', $value ) ) {
				wfDebug( __METHOD__ . ": Found href to embedded svg \"<$strippedElement '$attrib'='$value'...\" in uploaded file.\n" );
				return true;
			}

			# use set/animate to add event-handler attribute to parent
			if( ( $strippedElement == 'set' || $strippedElement == 'animate' ) && $stripped == 'attributename' && substr( $value, 0, 2 ) == 'on' ) {
				wfDebug( __METHOD__ . ": Found svg setting event-handler attribute with \"<$strippedElement $stripped='$value'...\" in uploaded file.\n" );
				return true;
			}

			# use set to add href attribute to parent element
			if( $strippedElement == 'set' && $stripped == 'attributename' && strpos( $value, 'href' ) !== false ) {
				wfDebug( __METHOD__ . ": Found svg setting href attibute '$value' in uploaded file.\n" );
				return true;
			}

			# use set to add a remote / data / script target to an element
			if( $strippedElement == 'set' && $stripped == 'to' &&  preg_match( '!(http|https|data|script):!sim', $value ) ) {
				wfDebug( __METHOD__ . ": Found svg setting attibute to '$value' in uploaded file.\n" );
				return true;
			}


			# use handler attribute with remote / data / script
			if( $stripped == 'handler' &&  preg_match( '!(http|https|data|script):!sim', $value ) ) {
				wfDebug( __METHOD__ . ": Found svg setting handler with remote/data/script '$attrib'='$value' in uploaded file.\n" );
				return true;
			}

			# use CSS styles to bring in remote code
			# catch url("http:..., url('http:..., url(http:..., but not url("#..., url('#..., url(#....
			if( $stripped == 'style' && preg_match_all( '!((?:font|clip-path|fill|filter|marker|marker-end|marker-mid|marker-start|mask|stroke)\s*:\s*url\s*\(\s*["\']?\s*[^#]+.*?\))!sim', $value, $matches ) ) {
				foreach ($matches[1] as $match) {
					if (!preg_match( '!(?:font|clip-path|fill|filter|marker|marker-end|marker-mid|marker-start|mask|stroke)\s*:\s*url\s*\(\s*(#|\'#|"#)!sim', $match ) ) {
						wfDebug( __METHOD__ . ": Found svg setting a style with remote url '$attrib'='$value' in uploaded file.\n" );
						return true;
					}
				}
			}

			# image filters can pull in url, which could be svg that executes scripts
			if( $strippedElement == 'image' && $stripped == 'filter' && preg_match( '!url\s*\(!sim', $value ) ) {
				wfDebug( __METHOD__ . ": Found image filter with url: \"<$strippedElement $stripped='$value'...\" in uploaded file.\n" );
				return true;
			}

		}

		return false; //No scripts detected
	}

	/**
	 * @param $name string
	 * @return string
	 */
	private function stripXmlNamespace( $name ) {
		// 'http://www.w3.org/2000/svg:script' -> 'script'
		$parts = explode( ':', strtolower( $name ) );
		return array_pop( $parts );
	}

	/**
	 * Generic wrapper function for a virus scanner program.
	 * This relies on the $wgAntivirus and $wgAntivirusSetup variables.
	 * $wgAntivirusRequired may be used to deny upload if the scan fails.
	 *
	 * @param $file String: pathname to the temporary upload file
	 * @return mixed false if not virus is found, NULL if the scan fails or is disabled,
	 *         or a string containing feedback from the virus scanner if a virus was found.
	 *         If textual feedback is missing but a virus was found, this function returns true.
	 */
	public static function detectVirus( $file ) {
		global $wgAntivirus, $wgAntivirusSetup, $wgAntivirusRequired, $wgOut;
		wfProfileIn( __METHOD__ );

		if ( !$wgAntivirus ) {
			wfDebug( __METHOD__ . ": virus scanner disabled\n" );
			wfProfileOut( __METHOD__ );
			return null;
		}

		if ( !$wgAntivirusSetup[$wgAntivirus] ) {
			wfDebug( __METHOD__ . ": unknown virus scanner: $wgAntivirus\n" );
			$wgOut->wrapWikiMsg( "<div class=\"error\">\n$1\n</div>",
				array( 'virus-badscanner', $wgAntivirus ) );
			wfProfileOut( __METHOD__ );
			return wfMessage( 'virus-unknownscanner' )->text() . " $wgAntivirus";
		}

		# look up scanner configuration
		$command = $wgAntivirusSetup[$wgAntivirus]['command'];
		$exitCodeMap = $wgAntivirusSetup[$wgAntivirus]['codemap'];
		$msgPattern = isset( $wgAntivirusSetup[$wgAntivirus]['messagepattern'] ) ?
			$wgAntivirusSetup[$wgAntivirus]['messagepattern'] : null;

		if ( strpos( $command, "%f" ) === false ) {
			# simple pattern: append file to scan
			$command .= " " . wfEscapeShellArg( $file );
		} else {
			# complex pattern: replace "%f" with file to scan
			$command = str_replace( "%f", wfEscapeShellArg( $file ), $command );
		}

		wfDebug( __METHOD__ . ": running virus scan: $command \n" );

		# execute virus scanner
		$exitCode = false;

		# NOTE: there's a 50 line workaround to make stderr redirection work on windows, too.
		#      that does not seem to be worth the pain.
		#      Ask me (Duesentrieb) about it if it's ever needed.
		$output = wfShellExec( "$command 2>&1", $exitCode );

		# map exit code to AV_xxx constants.
		$mappedCode = $exitCode;
		if ( $exitCodeMap ) {
			if ( isset( $exitCodeMap[$exitCode] ) ) {
				$mappedCode = $exitCodeMap[$exitCode];
			} elseif ( isset( $exitCodeMap["*"] ) ) {
				$mappedCode = $exitCodeMap["*"];
			}
		}

		if ( $mappedCode === AV_SCAN_FAILED ) {
			# scan failed (code was mapped to false by $exitCodeMap)
			wfDebug( __METHOD__ . ": failed to scan $file (code $exitCode).\n" );

			if ( $wgAntivirusRequired ) {
				wfProfileOut( __METHOD__ );
				return wfMessage( 'virus-scanfailed', array( $exitCode ) )->text();
			} else {
				wfProfileOut( __METHOD__ );
				return null;
			}
		} elseif ( $mappedCode === AV_SCAN_ABORTED ) {
			# scan failed because filetype is unknown (probably imune)
			wfDebug( __METHOD__ . ": unsupported file type $file (code $exitCode).\n" );
			wfProfileOut( __METHOD__ );
			return null;
		} elseif ( $mappedCode === AV_NO_VIRUS ) {
			# no virus found
			wfDebug( __METHOD__ . ": file passed virus scan.\n" );
			wfProfileOut( __METHOD__ );
			return false;
		} else {
			$output = trim( $output );

			if ( !$output ) {
				$output = true; #if there's no output, return true
			} elseif ( $msgPattern ) {
				$groups = array();
				if ( preg_match( $msgPattern, $output, $groups ) ) {
					if ( $groups[1] ) {
						$output = $groups[1];
					}
				}
			}

			wfDebug( __METHOD__ . ": FOUND VIRUS! scanner feedback: $output \n" );
			wfProfileOut( __METHOD__ );
			return $output;
		}
	}

	/**
	 * Check if there's an overwrite conflict and, if so, if restrictions
	 * forbid this user from performing the upload.
	 *
	 * @param $user User
	 *
	 * @return mixed true on success, array on failure
	 */
	private function checkOverwrite( $user ) {
		// First check whether the local file can be overwritten
		$file = $this->getLocalFile();
		if( $file->exists() ) {
			if( !self::userCanReUpload( $user, $file ) ) {
				return array( 'fileexists-forbidden', $file->getName() );
			} else {
				return true;
			}
		}

		/* Check shared conflicts: if the local file does not exist, but
		 * wfFindFile finds a file, it exists in a shared repository.
		 */
		$file = wfFindFile( $this->getTitle() );
		if ( $file && !$user->isAllowed( 'reupload-shared' ) ) {
			return array( 'fileexists-shared-forbidden', $file->getName() );
		}

		return true;
	}

	/**
	 * Check if a user is the last uploader
	 *
	 * @param $user User object
	 * @param $img String: image name
	 * @return Boolean
	 */
	public static function userCanReUpload( User $user, $img ) {
		if( $user->isAllowed( 'reupload' ) ) {
			return true; // non-conditional
		}
		if( !$user->isAllowed( 'reupload-own' ) ) {
			return false;
		}
		if( is_string( $img ) ) {
			$img = wfLocalFile( $img );
		}
		if ( !( $img instanceof LocalFile ) ) {
			return false;
		}

		return $user->getId() == $img->getUser( 'id' );
	}

	/**
	 * Helper function that does various existence checks for a file.
	 * The following checks are performed:
	 * - The file exists
	 * - Article with the same name as the file exists
	 * - File exists with normalized extension
	 * - The file looks like a thumbnail and the original exists
	 *
	 * @param $file File The File object to check
	 * @return mixed False if the file does not exists, else an array
	 */
	public static function getExistsWarning( $file ) {
		if( $file->exists() ) {
			return array( 'warning' => 'exists', 'file' => $file );
		}

		if( $file->getTitle()->getArticleID() ) {
			return array( 'warning' => 'page-exists', 'file' => $file );
		}

		if ( $file->wasDeleted() && !$file->exists() ) {
			return array( 'warning' => 'was-deleted', 'file' => $file );
		}

		if( strpos( $file->getName(), '.' ) == false ) {
			$partname = $file->getName();
			$extension = '';
		} else {
			$n = strrpos( $file->getName(), '.' );
			$extension = substr( $file->getName(), $n + 1 );
			$partname = substr( $file->getName(), 0, $n );
		}
		$normalizedExtension = File::normalizeExtension( $extension );

		if ( $normalizedExtension != $extension ) {
			// We're not using the normalized form of the extension.
			// Normal form is lowercase, using most common of alternate
			// extensions (eg 'jpg' rather than 'JPEG').
			//
			// Check for another file using the normalized form...
			$nt_lc = Title::makeTitle( NS_FILE, "{$partname}.{$normalizedExtension}" );
			$file_lc = wfLocalFile( $nt_lc );

			if( $file_lc->exists() ) {
				return array(
					'warning' => 'exists-normalized',
					'file' => $file,
					'normalizedFile' => $file_lc
				);
			}
		}

		if ( self::isThumbName( $file->getName() ) ) {
			# Check for filenames like 50px- or 180px-, these are mostly thumbnails
			$nt_thb = Title::newFromText( substr( $partname , strpos( $partname , '-' ) +1 ) . '.' . $extension, NS_FILE );
			$file_thb = wfLocalFile( $nt_thb );
			if( $file_thb->exists() ) {
				return array(
					'warning' => 'thumb',
					'file' => $file,
					'thumbFile' => $file_thb
				);
			} else {
				// File does not exist, but we just don't like the name
				return array(
					'warning' => 'thumb-name',
					'file' => $file,
					'thumbFile' => $file_thb
				);
			}
		}


		foreach( self::getFilenamePrefixBlacklist() as $prefix ) {
			if ( substr( $partname, 0, strlen( $prefix ) ) == $prefix ) {
				return array(
					'warning' => 'bad-prefix',
					'file' => $file,
					'prefix' => $prefix
				);
			}
		}

		return false;
	}

	/**
	 * Helper function that checks whether the filename looks like a thumbnail
	 * @param $filename string
	 * @return bool
	 */
	public static function isThumbName( $filename ) {
		$n = strrpos( $filename, '.' );
		$partname = $n ? substr( $filename, 0, $n ) : $filename;
		return (
					substr( $partname , 3, 3 ) == 'px-' ||
					substr( $partname , 2, 3 ) == 'px-'
				) &&
				preg_match( "/[0-9]{2}/" , substr( $partname , 0, 2 ) );
	}

	/**
	 * Get a list of blacklisted filename prefixes from [[MediaWiki:Filename-prefix-blacklist]]
	 *
	 * @return array list of prefixes
	 */
	public static function getFilenamePrefixBlacklist() {
		$blacklist = array();
		$message = wfMessage( 'filename-prefix-blacklist' )->inContentLanguage();
		if( !$message->isDisabled() ) {
			$lines = explode( "\n", $message->plain() );
			foreach( $lines as $line ) {
				// Remove comment lines
				$comment = substr( trim( $line ), 0, 1 );
				if ( $comment == '#' || $comment == '' ) {
					continue;
				}
				// Remove additional comments after a prefix
				$comment = strpos( $line, '#' );
				if ( $comment > 0 ) {
					$line = substr( $line, 0, $comment-1 );
				}
				$blacklist[] = trim( $line );
			}
		}
		return $blacklist;
	}

	/**
	 * Gets image info about the file just uploaded.
	 *
	 * Also has the effect of setting metadata to be an 'indexed tag name' in returned API result if
	 * 'metadata' was requested. Oddly, we have to pass the "result" object down just so it can do that
	 * with the appropriate format, presumably.
	 *
	 * @param $result ApiResult:
	 * @return Array: image info
	 */
	public function getImageInfo( $result ) {
		$file = $this->getLocalFile();
		// TODO This cries out for refactoring. We really want to say $file->getAllInfo(); here.
		// Perhaps "info" methods should be moved into files, and the API should just wrap them in queries.
		if ( $file instanceof UploadStashFile ) {
			$imParam = ApiQueryStashImageInfo::getPropertyNames();
			$info = ApiQueryStashImageInfo::getInfo( $file, array_flip( $imParam ), $result );
		} else {
			$imParam = ApiQueryImageInfo::getPropertyNames();
			$info = ApiQueryImageInfo::getInfo( $file, array_flip( $imParam ), $result );
		}
		return $info;
	}

	/**
	 * @param $error array
	 * @return Status
	 */
	public function convertVerifyErrorToStatus( $error ) {
		$code = $error['status'];
		unset( $code['status'] );
		return Status::newFatal( $this->getVerificationErrorCode( $code ), $error );
	}

	/**
	 * @param $forType null|string
	 * @return int
	 */
	public static function getMaxUploadSize( $forType = null ) {
		global $wgMaxUploadSize;

		if ( is_array( $wgMaxUploadSize ) ) {
			if ( !is_null( $forType ) && isset( $wgMaxUploadSize[$forType] ) ) {
				return $wgMaxUploadSize[$forType];
			} else {
				return $wgMaxUploadSize['*'];
			}
		} else {
			return intval( $wgMaxUploadSize );
		}

	}
}
