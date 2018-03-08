idct/limited-objects-cache
==========================

Simple ArrayAccess objects' cache with a lookup table in memory and fallback to
files with serialized data.

# Sample

The sample below creates a cache for 20 objects in memory. When 20 are hit then
first objects are pushed to files, yet attempt to retrieve a key which has been 
pushed already there will restore the object on top of the stack.

```php
$cache = new IDCT\LimitedObjectsCache('/tmp/cached', 20);

for ($i = 0; $i < 25; $i++) {
    $random = new stdClass();
    $random->test = $i;
    $cache['id_'. $i] = $random;
}

var_dump($cache['id_24']); //should be from mem
var_dump($cache['id_1']); //should be from disk
var_dump($cache['id_1']); //should be from mem (now)
```

# Contribution

If you have any suggestions please create an issue or pull request.