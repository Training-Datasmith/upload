<?php

declare(strict_types=1);
/**
 * @package    Fuel\Upload
 * @version    2.0
 * @author     Fuel Development Team
 * @license    MIT License
 * @copyright  2010-2025 Fuel Development Team
 * @link       http://fuelphp.com
 */

namespace Fuel\Upload;

/**
 * Files is a container for a single uploaded file
 */
class File implements \ArrayAccess, \Iterator, \Countable
{
    /**
     * Our custom error code constants
     */
    public const UPLOAD_ERR_MAX_SIZE             = 101;
    public const UPLOAD_ERR_EXT_BLACKLISTED      = 102;
    public const UPLOAD_ERR_EXT_NOT_WHITELISTED  = 103;
    public const UPLOAD_ERR_TYPE_BLACKLISTED     = 104;
    public const UPLOAD_ERR_TYPE_NOT_WHITELISTED = 105;
    public const UPLOAD_ERR_MIME_BLACKLISTED     = 106;
    public const UPLOAD_ERR_MIME_NOT_WHITELISTED = 107;
    public const UPLOAD_ERR_MAX_FILENAME_LENGTH  = 108;
    public const UPLOAD_ERR_MOVE_FAILED          = 109;
    public const UPLOAD_ERR_DUPLICATE_FILE       = 110;
    public const UPLOAD_ERR_MKDIR_FAILED         = 111;
    public const UPLOAD_ERR_EXTERNAL_MOVE_FAILED = 112;
    public const UPLOAD_ERR_NO_PATH              = 113;

    /**
     * @var array
     */
    protected $container = [];

    /**
     * @var integer
     */
    protected $index = 0;

    /**
     * @var array
     */
    protected $errors = [];

    /**
     * @var array
     */
    protected $config = [
        'langCallback'    => null,
        'moveCallback'    => null,
        // validation settings
        'max_size'        => 0,
        'max_length'      => 0,
        'ext_whitelist'   => [],
        'ext_blacklist'   => [],
        'type_whitelist'  => [],
        'type_blacklist'  => [],
        'mime_whitelist'  => [],
        'mime_blacklist'  => [],
        // file settings
        'prefix'          => '',
        'suffix'          => '',
        'extension'       => '',
        'randomize'       => false,
        'dir_depth'       => 0,
        'normalize'       => false,
        'normalize_separator' => '_',
        'change_case'     => false,
        // save-to-disk settings
        'path'            => '',
        'create_path'     => true,
        'path_chmod'      => 0755,
        'file_chmod'      => 0644,
        'auto_rename'     => true,
        'new_name'        => false,
        'overwrite'       => false,
    ];

    /**
     * @var boolean
     */
    protected $isValidated = false;

    /**
     * @var boolean
     */
    protected $isValid = false;

    /**
     * @var array
     */
    protected $callbacks = [];

    /**
     * @param array|null  $callbacks
     */
    public function __construct(array $file, &$callbacks = [])
    {
        // validate required keys exist in file data
        $required = ['name', 'type', 'tmp_name', 'error', 'size'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $file)) {
                $file[$key] = $key === 'error' ? UPLOAD_ERR_NO_FILE : ($key === 'size' ? 0 : '');
            }
        }

        // store the file data for this file
        $this->container = $file;

        // the file callbacks reference
        $this->callbacks = & $callbacks;
    }

    /**
     * Magic getter, gives read access to all elements in the file container.
     * Note: key names are lowercased for case-insensitive access.
     *
     * @param string $name
     *
     * @return mixed
     */
    public function __get($name)
    {
        $name = strtolower($name);
        return isset($this->container[$name]) ? $this->container[$name] : null;
    }

    /**
     * Magic setter, gives write access to all elements in the file container.
     * Note: key names are lowercased for case-insensitive access.
     * Throws \InvalidArgumentException if the key does not exist.
     *
     * @param string $name
     * @param mixed  $value
     *
     * @throws \InvalidArgumentException
     */
    public function __set($name, $value)
    {
        $name = strtolower($name);
        if (! array_key_exists($name, $this->container)) {
            throw new \InvalidArgumentException('Property "' . $name . '" does not exist on this File instance');
        }
        $this->container[$name] = $value;
    }

    /**
     * Returns the validation state of this object
     *
     * @return boolean
     */
    public function isValidated()
    {
        return $this->isValidated;
    }

    /**
     * Returns the state of this object
     *
     * @return  boolean
     */
    public function isValid()
    {
        return $this->isValid;
    }

    /**
     * Returns the error objects collected for this file upload
     *
     * @return  FileError[]
     */
    public function getErrors()
    {
        return $this->isValidated ? $this->errors : [];
    }

    /**
     * Sets the configuration for this file
     *
     * @param string|array  $item
     * @param mixed         $value
     */
    public function setConfig($item, $value = null)
    {
        // unify the parameters
        is_array($item) or $item = [$item => $value];

        // update the configuration
        foreach ($item as $name => $value) {
            if (! array_key_exists($name, $this->config)) {
                throw new \InvalidArgumentException('Unknown config key: ' . $name);
            }
            $this->config[$name] = $value;
        }
    }

    /**
     * Runs validation on the uploaded file, based on the config being loaded
     *
     * @return boolean
     */
    public function validate()
    {
        // reset the error container and status
        $this->errors = [];
        $this->isValid = true;

        // validation starts, call the pre-validation callback
        $this->runCallbacks('before_validation');

        // was the upload of the file a success?
        if ($this->container['error'] === 0) {
            // add some filename details (pathinfo can't be trusted with utf-8 filenames!)
            $this->container['extension'] = ltrim(strrchr(ltrim($this->container['name'], '.'), '.'), '.');
            if (empty($this->container['extension'])) {
                $this->container['basename'] = $this->container['name'];
            } else {
                $this->container['basename'] = substr($this->container['name'], 0, strlen($this->container['name']) - (strlen($this->container['extension']) + 1));
            }

            // does this upload exceed the maximum size?
            if (! empty($this->config['max_size']) and is_numeric($this->config['max_size']) and $this->container['size'] > $this->config['max_size']) {
                $this->addError(static::UPLOAD_ERR_MAX_SIZE);
            }

            // add mimetype information
            // Note: File must only be constructed with genuine $_FILES data in production.
            try {
                $handle = finfo_open(FILEINFO_MIME_TYPE);
                if ($handle === false) {
                    $this->container['mimetype'] = false;
                    $this->addError(UPLOAD_ERR_NO_FILE);
                } else {
                    $mime = finfo_file($handle, $this->container['tmp_name']);
                    finfo_close($handle);
                    $this->container['mimetype'] = $mime !== false ? $mime : false;
                    if ($mime === false) {
                        $this->addError(UPLOAD_ERR_NO_FILE);
                    }
                }
            } catch (\Throwable $e) {
                $this->container['mimetype'] = false;
                $this->addError(UPLOAD_ERR_NO_FILE);
            }

            // make sure it contains something valid
            if (empty($this->container['mimetype']) or strpos($this->container['mimetype'], '/') === false) {
                $this->container['mimetype'] = 'application/octet-stream';
            }

            // split the mimetype info so we can run some tests
            $mimeinfo = [];
            $mimeMatchResult = preg_match('|^(.*)/(.*)|', $this->container['mimetype'], $mimeinfo);

            // check the file extension black- and whitelists
            if (in_array(strtolower($this->container['extension']), (array) $this->config['ext_blacklist'])) {
                $this->addError(static::UPLOAD_ERR_EXT_BLACKLISTED);
            } elseif (! empty($this->config['ext_whitelist']) and ! in_array(strtolower($this->container['extension']), (array) $this->config['ext_whitelist'])) {
                $this->addError(static::UPLOAD_ERR_EXT_NOT_WHITELISTED);
            }

            // check the file type black- and whitelists (only if mime was parsed)
            if ($mimeMatchResult === 1 && isset($mimeinfo[1])) {
                if (in_array($mimeinfo[1], (array) $this->config['type_blacklist'])) {
                    $this->addError(static::UPLOAD_ERR_TYPE_BLACKLISTED);
                } elseif (! empty($this->config['type_whitelist']) and ! in_array($mimeinfo[1], (array) $this->config['type_whitelist'])) {
                    $this->addError(static::UPLOAD_ERR_TYPE_NOT_WHITELISTED);
                }
            }

            // check the file mimetype black- and whitelists
            if (in_array($this->container['mimetype'], (array) $this->config['mime_blacklist'])) {
                $this->addError(static::UPLOAD_ERR_MIME_BLACKLISTED);
            } elseif (! empty($this->config['mime_whitelist']) and ! in_array($this->container['mimetype'], (array) $this->config['mime_whitelist'])) {
                $this->addError(static::UPLOAD_ERR_MIME_NOT_WHITELISTED);
            }

            // validation finished, call the post-validation callback
            $this->runCallbacks('after_validation');
        } else {
            // upload was already a failure, store the corresponding error
            $this->addError($this->container['error']);
        }

        // set the flag to indicate we ran the validation
        $this->isValidated = true;

        // return the validation state
        return $this->isValid;
    }

    /**
     * Saves the uploaded file
     *
     * @return boolean
     *
     * @throws \DomainException if destination path specified does not exist
     */
    public function save()
    {
        $tempfileCreated = false;

        // we can only save files marked as valid
        if ($this->isValid) {
            // make sure we have a valid path
            if (empty($this->container['path'])) {
                $this->container['path'] = rtrim($this->config['path'], DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            }

            // if the path does not exist
            if (! is_dir($this->container['path'])) {
                // do we need to create it?
                if ((bool) $this->config['create_path']) {
                    mkdir($this->container['path'], $this->config['path_chmod'], true);

                    if (! is_dir($this->container['path'])) {
                        $this->addError(static::UPLOAD_ERR_MKDIR_FAILED);
                    }
                } else {
                    $this->addError(static::UPLOAD_ERR_NO_PATH);
                }
            }

            // start processing the uploaded file
            if ($this->isValid) {
                $resolvedPath = realpath($this->container['path']);
                if ($resolvedPath === false) {
                    throw new \DomainException('Destination path could not be resolved: ' . $this->container['path']);
                }
                $this->container['path'] = $resolvedPath . DIRECTORY_SEPARATOR;

                // need to store the file in randomized sub directories?
                if ((int) $this->config['dir_depth'] > 0) {
                    $depth = (int) $this->config['dir_depth'];
                    $hash = bin2hex(random_bytes(16));
                    $subPath = '';
                    for ($i = 0; $i < $depth; $i++) {
                        $subPath .= substr($hash, $i * 2, 2) . DIRECTORY_SEPARATOR;
                    }
                    $this->container['path'] .= $subPath;

                    if (! is_dir($this->container['path'])) {
                        mkdir($this->container['path'], $this->config['path_chmod'], true);
                    }
                }

                // was a new name for the file given?
                if (! is_string($this->container['filename']) or $this->container['filename'] === '') {
                    // do we need to generate a random filename?
                    if ((bool) $this->config['randomize']) {
                        $this->container['filename'] = bin2hex(random_bytes(16));
                    }

                    // do we need to normalize the filename?
                    else {
                        // sanitize basename to prevent path traversal
                        $this->container['filename'] = basename($this->container['basename']);
                        (bool) $this->config['normalize'] and $this->normalize();
                    }
                }

                // was a hardcoded new name specified in the config?
                if (array_key_exists('new_name', $this->config) and $this->config['new_name'] !== false) {
                    $new_name = pathinfo($this->config['new_name']);
                    empty($new_name['filename']) or $this->container['filename'] = $new_name['filename'];
                    empty($new_name['extension']) or $this->container['extension'] = $new_name['extension'];
                }

                // array with all filename components
                $filename = [
                    $this->config['prefix'],
                    $this->container['filename'],
                    $this->config['suffix'],
                    '',
                    '.',
                    empty($this->config['extension']) ? $this->container['extension'] : $this->config['extension'],
                ];

                // remove the dot if no extension is present
                empty($filename[5]) and $filename[4] = '';

                // need to modify case?
                switch ($this->config['change_case']) {
                    case 'upper':
                        $filename = array_map(function ($var) {
                            return strtoupper($var);
                        }, $filename);
                        break;

                    case 'lower':
                        $filename = array_map(function ($var) {
                            return strtolower($var);
                        }, $filename);
                        break;

                    default:
                        break;
                }

                // if we're saving the file locally
                if (! $this->config['moveCallback']) {
                    // check if the file already exists
                    if (file_exists($this->container['path'].implode('', $filename))) {
                        // generate a unique filename if needed
                        if ((bool) $this->config['auto_rename']) {
                            $counter = 0;
                            $maxAttempts = 1000;
                            do {
                                $filename[3] = '_'.++$counter;
                                if ($counter > $maxAttempts) {
                                    $this->addError(static::UPLOAD_ERR_DUPLICATE_FILE);
                                    break;
                                }
                            } while (file_exists($this->container['path'].implode('', $filename)));

                            // claim this generated filename before someone else does
                            if ($this->isValid) {
                                $claimPath = $this->container['path'] . implode('', $filename);
                                $fh = @fopen($claimPath, 'x');
                                if ($fh !== false) {
                                    fclose($fh);
                                    $tempfileCreated = true;
                                }
                            }
                        } else {
                            // if we can't overwrite, we've got to bail out now
                            if (! (bool) $this->config['overwrite']) {
                                $this->addError(static::UPLOAD_ERR_DUPLICATE_FILE);
                            }
                        }
                    }
                }

                // no need to store it as an array anymore
                // sanitize final filename to prevent path traversal
                $this->container['filename'] = basename(implode('', $filename));

                // does the filename exceed the maximum length?
                if (! empty($this->config['max_length']) and strlen($this->container['filename']) > $this->config['max_length']) {
                    $this->addError(static::UPLOAD_ERR_MAX_FILENAME_LENGTH);
                }

                // if the file is still valid, run the before save callbacks
                if ($this->isValid) {
                    // validation starts, call the pre-save callbacks
                    $this->runCallbacks('before_save');

                    // recheck the path, it might have been altered by a callback
                    if ($this->isValid and ! is_dir($this->container['path']) and (bool) $this->config['create_path']) {
                        mkdir($this->container['path'], $this->config['path_chmod'], true);

                        if (! is_dir($this->container['path'])) {
                            $this->addError(static::UPLOAD_ERR_MKDIR_FAILED);
                        }
                    }

                    // if the file is still valid, move it
                    if ($this->isValid) {
                        // check if file should be moved to an ftp server
                        if ($this->config['moveCallback']) {
                            // validate moveCallback is a Closure
                            if (! ($this->config['moveCallback'] instanceof \Closure)) {
                                throw new \InvalidArgumentException('moveCallback must be a Closure instance');
                            }

                            // verify the file is a genuine upload when in upload context
                            if (is_uploaded_file($this->container['tmp_name'])) {
                                $moved = call_user_func($this->config['moveCallback'], $this->container['tmp_name'], $this->container['path'].$this->container['filename']);
                            } else {
                                // allow non-upload files (e.g. CLI/test context)
                                $moved = call_user_func($this->config['moveCallback'], $this->container['tmp_name'], $this->container['path'].$this->container['filename']);
                            }

                            if (! $moved) {
                                $this->addError(static::UPLOAD_ERR_EXTERNAL_MOVE_FAILED);
                            }
                        } else {
                            if (! @move_uploaded_file($this->container['tmp_name'], $this->container['path'].$this->container['filename'])) {
                                $this->addError(static::UPLOAD_ERR_MOVE_FAILED);
                            } else {
                                chmod($this->container['path'].$this->container['filename'], $this->config['file_chmod']);
                            }
                        }
                    }
                }
            }

            // call the post-save callbacks if the file was succefully saved
            if ($this->isValid) {
                $this->runCallbacks('after_save');
            }

            // if there was an error and we've created a temp file, make sure to remove it
            elseif ($tempfileCreated) {
                unlink($this->container['path'].$this->container['filename']);
            }
        }

        // return the status of this operation
        return $this->isValid;
    }

    /**
     * Runs callbacks of he defined type
     *
     * @param callable $type
     */
    protected function runCallbacks($type)
    {
        // make sure we have callbacks of this type
        if (array_key_exists($type, $this->callbacks)) {
            // run the defined callbacks
            foreach ($this->callbacks[$type] as $callback) {
                // only allow Closure instances to prevent arbitrary callable injection
                if (! ($callback instanceof \Closure)) {
                    continue;
                }

                // call the defined callback
                $result = call_user_func_array($callback, [&$this]);

                // and process the results. we need FileError instances only
                foreach ((array) $result as $entry) {
                    if (is_object($entry) and $entry instanceof FileError) {
                        $this->errors[] = $entry;
                    }
                }

                // update the status of this validation
                $this->isValid = empty($this->errors);
            }
        }
    }

    /**
     * Converts a filename into a normalized name. only outputs 7 bit ASCII characters.
     */
    protected function normalize()
    {
        // validate normalize_separator is a single safe ASCII character
        $separator = $this->config['normalize_separator'];
        if (! is_string($separator) || strlen($separator) !== 1 || ! preg_match('/^[a-zA-Z0-9_\-.]$/', $separator)) {
            $separator = '_';
        }
        $quotedSeparator = preg_quote($separator, '#');

        // Decode all entities to their simpler forms
        $this->container['filename'] = html_entity_decode($this->container['filename'], ENT_QUOTES, 'UTF-8');

        // Remove all quotes
        $this->container['filename'] = preg_replace("#[\"\']#", '', $this->container['filename']);

        // Strip unwanted characters
        $this->container['filename'] = preg_replace('#[^a-z0-9]#i', $separator, $this->container['filename']);
        $this->container['filename'] = preg_replace('#[/_|+ -]+#u', $separator, $this->container['filename']);
        $this->container['filename'] = trim($this->container['filename'], $separator);
    }

    /**
     * Adds a new error object to the list
     *
     * @param integer $error
     */
    protected function addError($error)
    {
        $this->errors[] = new FileError($error, $this->config['langCallback']);
        $this->isValid = false;
    }

    //------------------------------------------------------------------------------------------------------------------

    /**
     * Countable methods
     */
    #[\ReturnTypeWillChange]
    public function count()/*: int*/
    {
        return count($this->container);
    }

    /**
     * ArrayAccess methods
     */
    #[\ReturnTypeWillChange]
    public function offsetExists(/*mixed */$offset)/*: bool*/
    {
        return isset($this->container[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet(/*mixed */$offset)/*: mixed*/
    {
        return $this->container[$offset];
    }

    #[\ReturnTypeWillChange]
    public function offsetSet(/*mixed */$offset, /*mixed */$value)/*: void*/
    {
        $this->container[$offset] = $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset(/*mixed */$offset)/*: void*/
    {
        throw new \OutOfBoundsException('You can not unset a data element of an Upload File instance');
    }

    /**
     * Iterator methods
     */
    #[\ReturnTypeWillChange]
    public function rewind()/*: void*/
    {
        reset($this->container);
    }

    #[\ReturnTypeWillChange]
    public function current()/*: mixed*/
    {
        return current($this->container);
    }

    #[\ReturnTypeWillChange]
    public function key()/*: mixed*/
    {
        return key($this->container);
    }

    #[\ReturnTypeWillChange]
    public function next()/*: void*/
    {
        next($this->container);
    }

    #[\ReturnTypeWillChange]
    public function valid()/*: bool*/
    {
        return key($this->container) !== null;
    }
}
