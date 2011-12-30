<?php
/**
* Yaffdb-php (Yet another flat file database for PHP)
* 
* PHP version 5
* 
* @category Database
* @package  Yaffdb
* @author   Houzuo(Howard) Guo <Guohouzuo@Gmail.com>
* @license  GPL v3
* @link     https://github.com/HouzuoGuo/yaffdb-php
*/

/**
* Global variables.
*/
$slash        = '/';
$table_exts   = array('dat', 'def', 'exc');
$table_dirs   = array('shr');
$db_columns   = array('~del'=>1);
$lock_timeout = 10; // timeout of any table lock in seconds

/**
* Overwrites or appends the lines into the file.
*
* @param string $path   the file's path
* @param array  $lines  lines to be written
* @param bool   $append whether to append to the file or overwrite
*
* @return void
*/
function writeFile($path, $lines=array(), $append=false)
{
    $fh = fopen($path, $append ? 'abt' : 'wbt');
    foreach ($lines as $line) {
        fwrite($fh, "$line\n");
    }
    fclose($fh);
}

/**
* Truncates or trims the string to the desired size.
*
* @param string $str  the string to trim
* @param int    $size the desired size of the string
*
* @return string the trimmed string
*/
function trimSize($str, $size)
{
    if ($size - strlen($str) > 0) {
        return str_pad($str, $size);
    } else {
        return substr($str, 0, $size);
    }
}

/**
* Searches for a value in an array and unsets it.
*
* @param array &$array reference to the array
* @param mix   $val    the value to search for
*
* @return void
*/
function findUnset(&$array, $val)
{
    foreach ($array as $i=>$v) {
        if ($v == $val) {
            unset($array[$i]);
        }
    }
}

/**
* Searches for a value in an array and replaces it.
* 
* @param array &$array  reference to the array
* @param mix   $val     the value to search for
* @param mix   $replace the value used to replace
*
* @return void
*/
function findReplace(&$array, $val, $replace)
{
    foreach ($array as $i=>$v) {
        if ($v == $val) {
            $array[$i] = $replace;
        }
    }
}

/**
* Tests if the string ends with the ending.
* Written by MrHus@StackOverflow.
*
* @param string $str    the string to be tested
* @param string $ending the desired ending
*
* @return bool true if the test is successful, otherwise false
*/
function endWith($str, $ending)
{
    $length = strlen($ending);
    $start  = $length * -1;
    return (substr($str, $start) === $ending);
}

/**
* Returns the value if it is numeric, otherwise halts.
*
* @param mixed $var the value
*
* @return mixed the value
*/
function ensureNumeric($var)
{
    is_numeric($var) or die("$var is not a number");
    return $var;
}

/**
* Checks if a name may be used as an identifier in database.
* It gets trimmed as well.
*
* @param string &$name the identifier
*
* @return string the identifier with spaces being trimmed from both ends
*/
function dbID(&$name)
{
    preg_match('/[~_\-a-z0-9]+/', $name) or die("$name is an invalid name");
    $name = trim($name);
}

/**
* Recursively removes all files in a directory, and the directory itself.
* Written by holger1 at NOSPAMzentralplan dot de.
*
* @param string $dir path to the directory
*
* @return void
*/
function rrmdir($dir)
{
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != '.' && $object != '..') {
                $file = "$dir$slash$object";
                if (filetype($file) == 'dir') {
                    rrmdir($file);
                } else {
                    unlink($file);
                }
            }
        }
        reset($objects);
        rmdir($dir);
    }
}

/**
* Simple function to replicate PHP 5 behaviour.
*
* @return float milliseconds since Epoch
*/
function microtimeFloat()
{
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}

/**
* Table column definition operations.
*
* Column definition uses the following format: (column name),(column size)\n
* E.g.
* NAME,10
* GENDER,1
*
* @category Database
* @package  Yaffdb
* @author   Houzuo(Howard) Guo <Guohouzuo@Gmail.com>
* @license  GPL v3
* @link     https://github.com/HouzuoGuo/yaffdb-php
*/
class Column
{
    /**
    * Constructor.
    * 
    * @param string $name   column name
    * @param int    $size   the fixed size of the column
    * @param int    $offset position of the column data in a table row
    */
    public function __construct($name, $size, $offset=0)
    {
        $this->_name   = $name;
        $this->_offset = $offset;
        $this->_size   = $size;
    }
    
    /**
    * Constructs an instance of Column given a column definition.
    *
    * @param string $definition the string definition of the column
    *
    * @return Column a constructed Column instance
    */
    public static function fromDefinition($definition)
    {
        list($name, $size) = explode(',', $definition);
        return new Column(dbID($name), trim(ensureNumeric($size)));
    }
    
    /**
    * Returns the column name.
    *
    * @return string the column name
    */
    public function getName()
    {
        return $this->_name;
    }
    
    /**
    * Sets the column name.
    *
    * @param string $name the new column name
    *
    * @return void
    */
    public function setName($name)
    {
        $this->_name = $name;
    }
    
    /**
    * Returns the position of the column data in a table row (offset).
    *
    * @return int the offset
    */
    public function getOffset()
    {
        return $this->_offset;
    }
    
    /**
    * Sets the the position of the column data in a table row (offset).
    *
    * @param int $offset the new offset
    *
    * @return void
    */
    public function setOffset($offset)
    {
        $this->_offset = $offset;
    }
    
    /**
    * Returns size of the column.
    *
    * @return int the size
    */
    public function getSize()
    {
        return $this->_size;
    }
    
    /**
    * Constructs a column definition string from the attributes that this
    * instance has.
    *
    * @return string a column definition string.
    */
    public function getDefinition()
    {
        return "{$this->_name},{$this->_size}";
    }
    
    private $_name, $_offset, $_size;
}

/**
* Low-level table operations.
*
* A table has the following files in its database' directory:
* .dat - Data file (spreadsheet)
* .def - Column definitions
* .exc - Exclusive lock, content is the transaction ID
* .shr - (Directory) shared locks, each file is named by the transaction ID
*
* An example of .dat and .def files:
* (t1.def)
* NAME,10
* GENDER,1
*
* (t1.dat)
* Howard    m
* Vanya     f
* Steve     m
* Sandra    f
*
* @category Database
* @package  Yaffdb
* @author   Houzuo(Howard) Guo <Guohouzuo@Gmail.com>
* @license  GPL v3
* @link     https://github.com/HouzuoGuo/yaffdb-php
*/
class Table
{
    /**
    * Constructor.
    *
    * @param Database $db   reference to the database where the table belongs to
    * @param string   $name name of the table
    */
    public function __construct($db, $name)
    {
        $this->_db   = $db;
        $this->_name = $name;
        $this->open();
        $this->readDefinition();
    }
    
    /**
    * Returns the table name.
    *
    * @return string table name
    */
    public function getName()
    {
        return $this->_name;
    }
    
    /**
    * Sets the table name.
    *
    * @param string $name the new table name
    *
    * @return void
    */
    public function setName($name)
    {
        $this->_name = $name;
    }
    
    /**
    * Returns path to the table's .dat file.
    *
    * @return string the file path
    */
    public function getDatPath()
    {
        return $this->_db->getDir()."{$this->_name}.dat";
    }
    
    /**
    * Returns path to the table's .def file.
    *
    * @return string the file path
    */
    public function getDefPath()
    {
        return $this->_db->getDir()."{$this->_name}.def";
    }
    
    /**
    * Returns path to the table's .exc file.
    *
    * @return string the file path
    */
    public function getExcPath()
    {
        return $this->_db->getDir()."{$this->_name}.exc";
    }
    
    /**
    * Returns path to the table's .shr directory.
    *
    * @return string the directory path
    */
    public function getShrPath()
    {
        return $this->_db->getDir()."{$this->_name}.shr";
    }
    
    /**
    * Returns the table's line size (size of a row + 1).
    *
    * @return string file path
    */
    public function getLineSize()
    {
        return $this->_lineSize;
    }
    
    /**
    * Opens file handle(s).
    *
    * @return void
    */
    public function open()
    {
        $this->_datFH = fopen($this->getDatPath(), 'rb+');
        stream_set_write_buffer($this->_datFH, 0);
    }
    
    /**
    * Reads column definitions from .def file.
    *
    * @return void
    */
    public function readDefinition()
    {
        $this->_sequence = array(); // the sequence (order) of columns
        $this->_columns  = array(); // individual column definition
        $this->_lineSize = 0;       // size of a line (includes \n)
        foreach (file($this->getDefPath()) as $definition) {
            $column = Column::fromDefinition($definition);
            $column->setOffset($this->_lineSize);
            $this->_sequence[] = $column->getName();
            $this->_lineSize  += $column->getSize();
            $this->_columns[$column->getName()] = $column;
        }
        ++$this->_lineSize; // the \n counts
    }
    
    /**
    * Counts the number of rows in table.
    * Non-integer value indicates corrupted table data.
    *
    * @return int number of rows in table
    */
    public function count()
    {
        clearstatcache();
        $lines = filesize($this->getDatPath()) / $this->getLineSize();
        return $lines;
    }
    
    /**
    * Adds a new column.
    *
    * @param string $name new column's name
    * @param int    $size new column's size
    *
    * @return void
    */
    public function add($name, $size)
    {
        dbID($name);
        array_key_exists($name, $this->_columns)
            and die("Column $name already exists");
        // pad each row to leave space for the new column
        if ($this->count() > 0) {
            $this->_pad($size);
        }
        $column = new Column($name, $size, $this->_lineSize - 1);
        $this->_sequence[]     = $name;
        $this->_columns[$name] = $column;
        $this->_lineSize      += $size;
        // write the definition of the column into the .def file
        writeFile($this->getDefPath(), array($column->getDefinition()), true);
    }
    
    /**
    * Removes a column.
    *
    * @param string $name the column's name
    *
    * @return void
    */
    public function remove($name)
    {
        array_key_exists($name, $this->_columns) or die("Column $name is not found");
        // find and remove the column definition from .def file
        $lines = array();
        $definition = $this->_columns[$name]->getDefinition();
        foreach (file($this->getDefPath()) as $line) {
            $line = trim($line);
            if ($line != $definition) {
                $lines[] = $line;
            }
        }
        writeFile($this->getDefPath(), $lines);
        $column = $this->_columns[$name];
        findUnset($this->_sequence, $name);
        unset($this->_columns[$name]);
        // remove the data of the column
        if ($this->count() > 0) {
            $column_ending = $column->getOffset() + $column->getSize();
            $this->_cut($column->getOffset(), $column_ending);
        }
        $this->_lineSize -= $column->getSize();
    }
    
    /**
    * Renames a column.
    *
    * @param string $old_name the column's original name
    * @param string $new_name the column's new name
    *
    * @return void
    */
    public function alter($old_name, $new_name)
    {
        dbID($new_name);
        array_key_exists($old_name, $this->_columns)
            or die("Column $old_name is not found");
        array_key_exists($new_name, $this->_columns)
            and die("Column $new_name already exists");
        // find and re-write the definition in .def file
        $lines = array();
        $column = $this->_columns[$old_name];
        $definition = $column->getDefinition();
        foreach (file($this->getDefPath()) as $line) {
            $line = trim($line);
            if ($line == $definition) {
                $size    = $this->_columns[$old_name]->getSize();
                $lines[] = "$new_name,$size";
            } else {
                $lines[] = $line;
            }
        }
        writeFile($this->getDefPath(), $lines);
        $column->setName($new_name);
        findReplace($this->_sequence, $old_name, $new_name);
        unset($this->_columns[$old_name]);
        $this->_columns[$new_name] = $column;
    }
    
    /**
    * Seeks the .dat file handle to the beginning of the row.
    *
    * @param int $number the row number to seek
    *
    * @return void
    */
    public function seek($number)
    {
        $number < $this->count() or die("Row number $number is out of boundary");
        fseek($this->_datFH, $number * $this->_lineSize, 0);
    }
    
    /**
    * Reads a row, returns an array.
    *
    * @param int $number the row number
    *
    * @return array the row data
    */
    public function read($number)
    {
        $this->seek($number);
        $line = fgets($this->_datFH);
        $row = array();
        foreach ($this->_sequence as $name) {
            $column     = $this->_columns[$name];
            $row[$name] = substr($line, $column->getOffset(), $column->getSize());
        }
        return $row;
    }
    
    /**
    * Seeks the .dat file handle to the row and beginning of the column.
    *
    * @param int    $number the row number
    * @param string $name   the column's name
    *
    * @return void
    */
    public function seekColumn($number, $name)
    {
        array_key_exists($name, $this->_columns) or die("Column $name is not found");
        $this->seek($number);
        fseek($this->_datFH, $this->_columns[$name]->getOffset(), 1);
    }
    
    /**
    * Inserts a row.
    *
    * @param array $row the row array (e.g. ('NAME'=>'Howard', 'GENDER'=>'m')
    *
    * @return void
    */
    public function insert($row)
    {
        $line = '';
        foreach ($this->_sequence as $name) {
            $column = $this->_columns[$name];
            $value  = array_key_exists($name, $row) ? $row[$name] : '';
            $line  .= trimSize($value, $column->getSize());
        }
        $line .= "\n";
        fseek($this->_datFH, 0, 2);
        fwrite($this->_datFH, $line);
    }
    
    /**
    * Updates the row at the row number.
    *
    * @param int   $number the row number
    * @param array $row    the row array (e.g. ('NAME'=>'Howard', 'GENDER'=>'m')
    *
    * @return void
    */
    public function update($number, $row)
    {
        foreach ($row as $name => $val) {
            if (!array_key_exists($name, $this->_columns)) {
                continue;
            }
            $column = $this->_columns[$name];
            $this->seekColumn($number, $name);
            fwrite($this->_datFH, trimSize($val, $column->getSize()));
        }
    }
    
    /**
    * Deletes a row.
    *
    * @param int $number the row number
    *
    * @return void
    */
    public function delete($number)
    {
        array_key_exists('~del', $this->_columns)
            or die("Column defition of ~del is missing");
        // set ~del to y
        $this->seekColumn($number, '~del');
        fwrite($this->_datFH, trimSize('y', $this->_columns['~del']->getSize()));
    }
    
    /**
    * Closes all opened file handles.
    *
    * @return void
    */
    public function close()
    {
        clearstatcache();
        fclose($this->_datFH);
    }
    
    /**
    * Pads spaces to all rows in the table to leave space for a new column.
    *
    * @param int $padding number of padded spaces
    *
    * @return void
    */
    private function _pad($padding)
    {
        $desired_size = $this->_lineSize + $padding - 1;
        $lines = array();
        foreach (file($this->getDatPath()) as $line) {
            $lines[] = str_pad(rtrim($line), $desired_size);
        }
        writeFile($this->getDatPath(), $lines);
    }
    
    /**
    * Removes data of a column.
    *
    * @param int $begin the beginning position of the column data in a row
    * @param int $end   the ending position of the column data in a row
    *
    * @return void
    */
    private function _cut($begin, $end)
    {
        $lines = array();
        foreach (file($this->getDatPath()) as $line) {
            $before = substr($line, 0, $begin);
            $after = substr($line, $end, strlen($line) - $begin - 1);
            $lines[] = rtrim($before.$after, "\n");
        }
        writeFile($this->getDatPath(), $lines);
    }
    
    /**
    * Destructor.
    */
    public function __destruct()
    {
        $this->close();
    }
    
    private $_db, $_name, $_sequence, $_columns, $_datFH, $_lineSize;
}

/**
* Database logics.
* Database is stored as a directory in file system with all its tables' files.
*
* @category Database
* @package  Yaffdb
* @author   Houzuo(Howard) Guo <Guohouzuo@Gmail.com>
* @license  GPL v3
* @link     https://github.com/HouzuoGuo/yaffdb-php
*/
class Database
{
    /**
    * Constructor.
    *
    * @param string $dir path to the database directory
    */
    public function __construct($dir)
    {
        global $slash;
        $this->_tables = array();
        if (!endWith($dir, $slash)) {
            $dir .= $slash;
        }
        $this->_dir = $dir;
        foreach (scandir($dir) as $path) {
            $path_parts = pathinfo($path);
            if ($path_parts['extension'] && $path_parts['extension'] == 'dat') {
                $table = new Table($this, $path_parts['filename']);
                $this->_tables[$table->getName()] = $table;
            }
        }
    }
    
    /**
    * Returns the database directory path.
    *
    * @return string the directory path
    */
    public function getDir()
    {
        return $this->_dir;
    }
    
    /**
    * Returns the table instance given a table name.
    *
    * @param string $name the table's name
    *
    * @return Table the table instance
    */
    public function table($name)
    {
        array_key_exists($name, $this->_tables) or die("Table $name does not exist");
        return $this->_tables[$name];
    }
    
    /**
    * Creates a table.
    *
    * @param string $name the new table's name
    *
    * @return void
    */
    public function create($name)
    {
        dbID($name);
        global $slash, $table_exts, $table_dirs, $db_columns;
        array_key_exists($name, $this->_tables)
            and die("Table $name already exists");
        // create the files and directories for the new table
        foreach ($table_exts as $ext) {
            writeFile("{$this->_dir}$slash$name.$ext");
        }
        foreach ($table_dirs as $dir) {
            mkdir("{$this->_dir}$slash$name.$dir");
        }
        $table = $this->_tables[$name] = new Table($this, $name);
        // put default columns in
        foreach ($db_columns as $column=>$size) {
            $table->add($column, $size);
        }
        return $table;
    }
    
    /**
    * Renames a table.
    * It is unnecessary to abandone using any reference to the table before
    * renaming.
    *
    * @param string $old_name the table's original name
    * @param string $new_name the new name
    *
    * @return void
    */
    public function rename($old_name, $new_name)
    {
        global $slash;
        dbID($new_name);
        array_key_exists($old_name, $this->_tables)
            or die("Table $old_name does not exist");
        array_key_exists($new_name, $this->_tables)
            and die("Table $new_name already exists");
        $tab = $this->_tables[$old_name];
        $tab->close();
        sleep(1); // Martin Pelletier 05-Feb-2011 06:01
        // rename the table files and directories
        foreach (scandir($this->_dir) as $path) {
            if ($path != '.' && $path != '..') {
                $path_parts = pathinfo($path);
                $new_path = $this->_dir.$new_name.'.'.$path_parts['extension'];
                rename("{$this->_dir}$path", $new_path);
            }
        }
        $tab->setName($new_name);
        $tab->open();
        $this->_tables[$new_name] = $tab;
        unset($this->_tables[$old_name]);
    }
    
    /**
    * Drops a table.
    *
    * @param string $name the table's name
    *
    * @return void
    */
    public function drop($name)
    {
        array_key_exists($name, $this->_tables) or die("Table $name does not exist");
        $tab = $this->_tables[$name];
        $tab->close();
        // delete the table's files and directories
        foreach (scandir($this->_dir) as $path) {
            $path_parts = pathinfo($path);
            if ($path_parts['filename'] == $name) {
                $path = "{$this->_dir}$path";
                if (is_file($path)) {
                    unlink($path);
                } else {
                    rrmdir($path);
                }
            }
        }
    }
    
    private $_dir, $_tables;
}

/**
* Transaction, table locking logics.
* Transaction ID is allocated as the system time in milliseconds. Thus it is
* unsafe under heavy concurrent operations.
*
* @category Database
* @package  Yaffdb
* @author   Houzuo(Howard) Guo <Guohouzuo@Gmail.com>
* @license  GPL v3
* @link     https://github.com/HouzuoGuo/yaffdb-php
*/
class Transaction
{
    /**
    * The constructor.
    *
    * @param Database $db the database which the transaction works with
    */
    public function __construct($db)
    {
        $this->_db      = $db;
        $this->_id      = microtimeFloat();
        $this->_locked  = array(); // locked tables
        $this->_history = array(); // insert/update/delete history
    }
    
    /**
    * Locks a table in exclusive mode.
    * Fails when another transaction is holding exclusive lock on it.
    *
    * @param Table $table the table to lock
    *
    * @return bool true if successful, otherwise false
    */
    public function exclusiveLock($table)
    {
        
    }
    
    /**
    * Locks a table in shared mode.
    * Fails when another transaction is holding exclusive lock on it.
    *
    * @param Table $table the table to lock
    *
    * @return bool true if successful, otherwise false
    */
    public function sharedLock($table)
    {
        
    }
    
    /**
    * This transaction tries to unlock a table.
    * Fails when the table cannot be unlocked by this transaction.
    *
    * @param Table $table the table to unlock
    *
    * @return bool if successful, otherwise false
    */
    public function unlock($table)
    {
    }
    
    /**
    * Commits the transaction, gets a new transaction ID and releases all locked
    * resources.
    *
    * @return void
    */
    public function commit()
    {
    }
    
    /**
    * Rolls back the transaction, undo insert/update/delete operations, gets a
    * new transaction ID and releases all locked resources.
    *
    * @return void
    */
    public function rollback()
    {
    }
    
    /**
    * Returns locks on a table in the following structure:
    * array('exclusive'=>101, 'shared'=>array(102, 103, 104))
    *
    * @param Table $table the table
    *
    * @return array locks on the table
    */
    public static function locksOf($table)
    {
        global $lock_timeout;
        $ret = array('exclusive'=>null, 'shared'=>array());
        $exclusive_lock = file_get_contents($table->getExcPath());
        if ($exclusive_lock) {
            $lock_id = ensureNumeric($exclusive_lock[0]);
            if ($lock_id > microtimeFloat() + $lock_timeout) {
                // wipe the exclusive lock if it has expired
                writeFile($table->getExcPath());
            } else {
                $ret['exclusive'] = $lock_id;
            }
        }
    }
    
    private $_db, $_locked, $_id, $_history;
}

chdir($_SERVER['DOCUMENT_ROOT']);
$db = new Database("temp");
$tab = $db->create("t1");
$tab->add('c1', 10);
$tab->add('c2', 20);
$tab->insert(array('c1'=>'a', 'c2'=>'b'));
$tab->insert(array('c1'=>'c', 'c2'=>'d'));
$tab->add('c3', 10);
$tab->insert(array('c3'=>'aaa'));
$tab->remove('c3');
$tab->insert(array('c2'=>'bbb'));
$tab->insert(array('c2'=>'ccc'));
$tab->delete(0);
$tab->update(1, array('c1'=>'new!'));
$tab->alter('c2', 'cnew');
$db->rename('t1', 't2');
var_dump($tab->read(0));
$db->drop('t2');
?>
