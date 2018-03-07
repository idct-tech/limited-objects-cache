<?php

namespace IDCT;

use ArrayAccess;
use InvalidArgumentException;

/**
 * Simple ArrayAccess objects' cache with a lookup table in memory and fallback
 * to files with serialized data.
 *
 * @author Bartosz Pachołek <bartosz@idct.pl>
 */
class LimitedObjectsCache implements ArrayAccess
{
    /**
     * Path to storage folder where objects which go out of memory limits will
     * be stored.
     *
     * @var string
     */
    private $storagePath;

    /**
     * Limit of objects in the memory stack. When limit is hit then oldest
     * objects are pushed to files and removed from memory.
     *
     * @var int
     */
    private $limit;

    /**
     * Array of objects stored in memory until memory limit is hit and files are
     * stored in files.
     *
     * @var mixed[string]
     */
    private $memstorage;

    /**
     * Current objects count in memory storage.
     *
     * @var int
     */
    private $objectsCount;

    /**
     * Creates new cache instance. Requires path to be provided.
     *
     * @param string $storagePath Path where objects are stored when exceed mem.
     * @param int $limit Limit of objects in memory. Defaults to 1024.
     */
    public function __construct($storagePath, $limit = 1024)
    {
        $this->setStoragePath($storagePath)
             ->setLimit($limit)
             ;

        $this->objectsCount = 0;
        $this->memstorage = [];
    }

    /**
     * Destroys cache. Flushes data to files.
     */
    public function __destruct()
    {
        $this->flush();
    }

    /**
     * Sets the path where cached files will be stored.
     *
     * @param string $storagePath
     * @throws InvalidArgumentException Storage path must direct to a writable directory.
     * @return $this
     */
    public function setStoragePath($storagePath)
    {
        if (!is_string($storagePath) || !is_dir($storagePath) || !is_writable($storagePath)) {
            throw new InvalidArgumentException("Storage path must direct to a writable directory.");
        }

        if (substr($storagePath, -1, 1) !== DIRECTORY_SEPARATOR) {
            $storagePath .= DIRECTORY_SEPARATOR;
        }

        $this->storagePath = $storagePath;

        return $this;
    }

    /**
     * Returns storage path.
     *
     * @var string
     */
    public function getStoragePath()
    {
        return $this->storagePath;
    }

    /**
     * Sets the limit of object objects in memory.
     *
     * @param int $limit Limit must be an integer not lower than 1.
     * @throws InvalidArgumentException
     * @return $this
     */
    public function setLimit($limit)
    {
        if (!is_int($limit) || $limit < 1) {
            throw new InvalidArgumentException("Limit must be an integer not lower than 1.");
        }

        $this->limit = $limit;

        return $this;
    }

    /**
     * Gets the limit of object objects in memory.
     *
     * @return int Limit must be an integer not lower than 1.
     */
    public function getLimit()
    {
        return $this->limit;
    }

    /**
     * Checks if cache key (hash in memstorage or filename) exists.
     *
     * @param string $offset Cache key
     * @return boolean
     */
    public function offsetExists($offset)
    {
        $hash = md5($offset);

        if (isset($this->memstorage[$hash])) {
            return true;
        }

        $filePath = $this->getStoragePath() . $this->getParsedKey($hash) . $hash;

        return file_exists($filePath);
    }

    /**
     * Removes cache key and value (file).
     *
     * @param string $offset Cache key (Filename)
     */
    public function offsetUnset($offset)
    {
        $hash = md5($offset);

        if (isset($this->memstorage[$hash])) {
            unset($this->memstorage[$hash]);
            $this->objectsCount--;
        }

        // disk
        $filePath = $this->getStoragePath() . $this->getParsedKey($hash) . $hash;
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        return $this;
    }

    /**
     * Gets the value from under the given cache key (filename)
     * @param string $offset Cache key (filename)
     * @return mixed
     */
    public function offsetGet($offset)
    {
        $hash = md5($offset);
        if (isset($this->memstorage[$hash])) {
            return $this->memstorage[$hash];
        }

        $filePath = $this->getStoragePath() . $this->getParsedKey($hash) . $hash;
        if (!file_exists($filePath)) {
            return null;
        }

        $object = $this->decode(file_get_contents($filePath));
        //set it back to memory:
        $this->offsetSet($offset, $object);

        return $object;
    }

    /**
     * Saves the serialized value to the the $cachePath with the given name
     * @param string $offset ID of the cache key (filename)
     * @param string $value Value to be serialized and saved
     */
    public function offsetSet($offset, $value)
    {
        $hash = md5($offset);
        if (isset($this->memstorage[$hash])) {
            unset($this->memstorage[$hash]); //we need to move object to the top
        }
        $this->memstorage[$hash] = $value;
        $this->objectsCount++;
        while ($this->objectsCount > $this->getLimit()) {
            $this->_flush();
        }
        
        return $this;
    }

    /**
     * Flushes all entries to files.
     *
     * @return $this
     */
    public function flush()
    {
        while (!empty($this->memstorage)) {
            $this->_flush();
        }

        return $this;
    }

    /**
     * Encoding method.
     *
     * @param mixed $data Object to be serialized.
     * @return string Serialized object.
     */
    protected function encode($data)
    {
        return serialize($data);
    }

    /**
     * Decoding method.
     *
     * @param string $data
     * @return $this
     */
    protected function decode($data)
    {
        return unserialize($data);
    }

    /**
     * Retruns parsed path, with subfolders established from first chars of the
     * key's hash.
     *
     * @param string $hash
     * @return string Folder's path
     */
    private function getParsedKey($hash)
    {
        $dir = substr($hash, 0, 2) . "/" . substr($hash, 2, 2) . "/";

        return $dir;
    }

    /**
     * Dumps first object in memstorage (acting as a queue) to file.
     *
     * @return $this
     */
    private function _flush()
    {
        $hash = key($this->memstorage);
        $object = array_shift($this->memstorage);
        $dir = $this->getParsedKey($hash);

        if (!file_exists($this->getStoragePath() . $dir)) {
            mkdir($this->getStoragePath() . $dir, 0744, true);
        }
        $filePath = $this->getStoragePath() . $dir . $hash;
        if ($this->offsetExists($filePath)) {
            unlink($filePath);
        }
        $serialized = $this->encode($object);
        file_put_contents($filePath, $serialized);
        $this->objectsCount--;
        unset($serialized);
        unset($this->memstorage[$hash]);

        return $this;
    }
}
