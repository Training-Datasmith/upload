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
 * Upload is a container for unified access to uploaded files
 */
class Upload implements \ArrayAccess, \Iterator, \Countable
{
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
    protected $defaults = [
        // global settings
        'auto_process'    => false,
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
     * @var array
     */
    protected $callbacks = [
        'before_validation' => [],
        'after_validation' => [],
        'before_save' => [],
        'after_save' => [],
    ];

    /**
     * @param array|null $config
     *
     * @throws NoFilesException if no uploaded files were found (did specify "enctype"?)
     */
    public function __construct($config = null)
    {
        // input validation
        if (! is_array($config) && ! is_null($config)) {
            throw new \TypeError(__METHOD__ . '(): Argument #1 ($config) must be of type ?array, ' . gettype($config) . ' given');
        }

        // override defaults if needed
        if (is_array($config)) {
            foreach ($config as $key => $value) {
                if (! array_key_exists($key, $this->defaults)) {
                    throw new \InvalidArgumentException('Unknown config key: ' . $key);
                }
                $this->defaults[$key] = $value;
            }
        }

        // we can't do anything without any files uploaded
        if (empty($_FILES)) {
            throw new NoFilesException('No uploaded files were found. Did you specify "enctype" in your &lt;form&gt; tag?');
        }

        // if auto-process was active, run validation on all file objects
        if ($this->defaults['auto_process']) {
            // process all data in the $_FILES array
            $this->processFiles();

            // and validate it
            $this->validate();
        }
    }

    /**
     * Runs save on all loaded file objects
     *
     * @param integer|string|array $selection
     */
    public function save($selection = null)
    {
        $files = func_num_args() ? $this->resolveSelection($selection) : $this->container;

        // loop through all selected files
        foreach ($files as $file) {
            $file->save();
        }
    }

    /**
     * Runs validation on all selected file objects
     *
     * @param integer|string|array $selection
     */
    public function validate($selection = null)
    {
        $files = func_num_args() ? $this->resolveSelection($selection) : $this->container;

        // loop through all selected files
        foreach ($files as $file) {
            $file->validate();
        }
    }

    /**
     * Resolves a selection parameter into an array of File objects
     *
     * @param integer|string|array $selection
     *
     * @return File[]
     */
    private function resolveSelection($selection)
    {
        if (is_array($selection)) {
            $filter = [];

            foreach ($this->container as $file) {
                $match = true;
                foreach ($selection as $item => $value) {
                    if ($value !== $file->{$item}) {
                        $match = false;
                        break;
                    }
                }

                $match and $filter[] = $file;
            }

            return $filter;
        }

        return [$this[$selection]];
    }

    /**
     * Returns a consolidated status of all uploaded files
     *
     * @return boolean
     */
    public function isValid()
    {
        // loop through all files
        foreach ($this->container as $file) {
            // return false at the first non-valid file
            if (! $file->isValid()) {
                return false;
            }
        }

        // only return true if there are uploaded files, and they are all valid
        return empty($this->container) ? false : true;
    }

    /**
     * Returns the list of uploaded files
     *
     * @param integer|string $index
     *
     * @return File[]
     */
    public function getAllFiles($index = null)
    {
        // return the selection
        $selection = (func_num_args() && ! is_null($index)) ? $this[$index] : $this->container;
        if ($selection) {
            // make sure selection is an array
            is_array($selection) or $selection = [$selection];
        } else {
            $selection = [];
        }

        return $selection;
    }

    /**
     * Returns the list of uploaded files that valid
     *
     * @param integer|string $index
     *
     * @return File[]
     */
    public function getValidFiles($index = null)
    {
        // prepare the selection: integer index = Nth valid file; string = named field
        if (is_int($index)) {
            $selection = $this->container;
        } else {
            $selection = (func_num_args() and ! is_null($index)) ? $this[$index] : $this->container;
        }

        // storage for the results
        $results = [];

        if ($selection) {
            // make sure selection is an array
            is_array($selection) or $selection = [$selection];

            // loop through all files
            foreach ($selection as $file) {
                // store only files that are valid
                $file->isValid() and $results[] = $file;
            }
        }

        // return the results
        if (is_int($index)) {
            // a specific valid file was requested
            return isset($results[$index]) ? [$results[$index]] : [];
        }
        return $results;
    }

    /**
     * Returns the list of uploaded files that invalid
     *
     * @param integer|string $index
     *
     * @return File[]
     */
    public function getInvalidFiles($index = null)
    {
        // prepare the selection: integer index = Nth invalid file; string = named field
        if (is_int($index)) {
            $selection = $this->container;
        } else {
            $selection = (func_num_args() and ! is_null($index)) ? $this[$index] : $this->container;
        }

        // storage for the results
        $results = [];

        if ($selection) {
            // make sure selection is an array
            is_array($selection) or $selection = [$selection];

            // loop through all files
            foreach ($selection as $file) {
                // store only files that are invalid
                $file->isValid() or $results[] = $file;
            }
        }

        // return the results
        if (is_int($index)) {
            // a specific invalid file was requested
            return isset($results[$index]) ? [$results[$index]] : [];
        }
        return $results;
    }

    /**
     * Registers a callback for a given event
     *
     * @param mixed  $callback
     * @throws \InvalidArgumentException if not valid event or not callable second parameter
     */
    public function register(string $event, $callback)
    {
        // check if this is a valid event type
        if (! isset($this->callbacks[$event])) {
            throw new \InvalidArgumentException($event.' is not a valid event');
        }

        // only allow Closure instances to prevent arbitrary callable injection
        if (! ($callback instanceof \Closure)) {
            throw new \InvalidArgumentException('Callback must be a Closure instance');
        }

        // store it
        $this->callbacks[$event][] = $callback;
    }

    /**
     * Sets the configuration for this file
     *
     * @param string|array $item
     * @param mixed        $value
     */
    public function setConfig($item, $value = null)
    {
        // unify the parameters
        is_array($item) or $item = [$item => $value];

        // update the configuration
        foreach ($item as $name => $value) {
            if (! array_key_exists($name, $this->defaults)) {
                throw new \InvalidArgumentException('Unknown config key: ' . $name);
            }
            $this->defaults[$name] = $value;
        }

        // and push it to all file objects in the containers
        foreach ($this->container as $file) {
            $file->setConfig($item);
        }
    }

    /**
     * Processes the data in the $_FILES array, unify it, and create File objects for them
     *
     * @param mixed $selection
     */
    public function processFiles($selection = null)
    {
        // input validation
        if (! is_array($selection) && ! is_null($selection)) {
            throw new \TypeError(__METHOD__ . '(): Argument #1 ($selection) must be of type ?array, ' . gettype($selection) . ' given');
        }

        // normalize the multidimensional fields in the $_FILES array
        foreach ($_FILES as $name => $file) {
            // validate required keys exist in $_FILES entry
            if (! isset($file['name'], $file['type'], $file['tmp_name'], $file['error'], $file['size'])) {
                continue;
            }

            // was it defined as an array?
            if (is_array($file['name'])) {
                $data = $this->unifyFile($name, $file);

                foreach ($data as $entry) {
                    if ($selection === null or in_array($entry['element'], $selection)) {
                        $this->addFile($entry);
                    }
                }
            } else {
                // normal form element, just create a File object for this uploaded file
                if ($selection === null or in_array($name, $selection)) {
                    $this->addFile(array_merge(['element' => $name, 'filename' => null], $file));
                }
            }
        }
    }

    /**
     * Converts the silly different $_FILE structures to a flattened array
     *
     *
     * @return array
     */
    protected function unifyFile(string $name, array $file)
    {
        // storage for results
        $data = [];

        // loop over the file array
        foreach ($file['name'] as $key => $value) {
            // we're not an the end of the element name nesting yet
            if (is_array($value)) {
                // recurse with the array data we have at this point
                // Note: 'element' key is intentionally omitted here; it is set only at leaf nodes
                $data = array_merge(
                    $data,
                    $this->unifyFile(
                        $name.'.'.$key,
                        [
                            'filename' => null,
                            'name'     => $file['name'][$key],
                            'type'     => $file['type'][$key],
                            'tmp_name' => $file['tmp_name'][$key],
                            'error'    => $file['error'][$key],
                            'size'     => $file['size'][$key],
                        ]
                    )
                );
            } else {
                $data[] = [
                    'filename' => null,
                    'element'  => $name.'.'.$key,
                    'name'     => $file['name'][$key],
                    'type'     => $file['type'][$key],
                    'tmp_name' => $file['tmp_name'][$key],
                    'error'    => $file['error'][$key],
                    'size'     => $file['size'][$key],
                ];
            }
        }

        return $data;
    }

    /**
     * Adds a new uploaded file structure to the container
     */
    protected function addFile(array $entry)
    {
        // add the new file object to the container
        $this->container[] = new File($entry, $this->callbacks);

        // and load it with a default config
        end($this->container)->setConfig($this->defaults);
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
        // if the requested key is alphanumeric, do a search on element name
        if (is_string($offset)) {
            // if it's in form notation, convert it to dot notation
            $offset = str_replace(['][', '[', ']'], ['.', '.', ''], $offset);

            // see if we can find this element or elements (exact match or exact+dot prefix)
            $found = [];
            foreach ($this->container as $key => $file) {
                if ($file->element === $offset || strpos($file->element, $offset . '.') === 0) {
                    $found[] = $this->container[$key];
                }
            }

            if (! empty($found)) {
                return $found;
            }
        }

        // else check on numeric offset
        elseif (isset($this->container[$offset])) {
            return $this->container[$offset];
        }

        // not found
        return null;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet(/*mixed */$offset, /*mixed */$value)/*: void*/
    {
        throw new \OutOfBoundsException('An Upload Files instance is read-only, its contents can not be altered');
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset(/*mixed */$offset)/*: void*/
    {
        throw new \OutOfBoundsException('An Upload Files instance is read-only, its contents can not be altered');
    }

    /**
     * Iterator methods
     */
    #[\ReturnTypeWillChange]
    public function rewind()/*: void*/
    {
        $this->index = 0;
    }

    #[\ReturnTypeWillChange]
    public function current()/*: mixed*/
    {
        return $this->container[$this->index] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function key()/*: mixed*/
    {
        return $this->index;
    }

    #[\ReturnTypeWillChange]
    public function next()/*: void*/
    {
        ++$this->index;
    }

    #[\ReturnTypeWillChange]
    public function valid()/*: bool*/
    {
        return isset($this->container[$this->index]);
    }
}
