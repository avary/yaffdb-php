<?php
    /* Constants */
    $slash = '/'; // change to \ if you use Windows 
    $table_exts = array('dat', 'def', 'exc');
    $table_dirs = array('shr');
    $db_columns = array('~del'=>1);
    
    /* Utility functions */
    /*
    Overwrites or appends the lines into the file at $path.
    Line-ending will be added to each line.
    */
    function write_file($path, $lines=array(), $append=false) {
        $fh = fopen($path, $append ? 'abt' : 'wbt');
        foreach ($lines as $line) {
            fwrite($fh, "$line\n");
        }
        fclose($fh);
    }
    
    /* Truncates or trims the string to the desired size. */
    function trim_size($str, $size) {
        if ($size - strlen($str) > 0) {
            return str_pad($str, $size);
        } else {
            return substr($str, 0, $size);
        }
    }
    
    /* Looks for a value in an array and removes it. */
    function find_unset(&$array, $val) {
        foreach ($array as $i=>$v) {
            if ($v == $val) {
                unset($array[$i]);
            }
        }
    }
    
    /* Looks for a value in an array and replaces it. */
    function find_replace(&$array, $val, $replace) {
        foreach ($array as $i=>$v) {
            if ($v == $val) {
                $array[$i] = $replace;
            }
        }
    }
    
    /* Tests if the string ends with the ending. */
    function end_with($str, $ending) { // MrHus@StackOverflow
        $length = strlen($ending);
        $start  = $length * -1;
        return (substr($str, $start) === $ending);
    }
    
    /* Returns the value if it is numeric, otherwise halts. */
    function ensure_numeric($var) {
        is_numeric($var) or die("$var is not a number");
        return $var;
    }
    
    /* Checks if a name may be used as an identifier in database. It gets trimmed as well. */
    function db_id(&$name) {
        preg_match('/[~_\-a-z0-9]+/', $name) or die("$name is an invalid name");
        $name = trim($name);
    }
    
    /* Recursively removes all files in a directory, and the directory itself. */
    function rrmdir($dir) { // holger1 at NOSPAMzentralplan dot de
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != '.' && $object != '..') {
                    $file = "$dir$slash$object";
                    if (filetype($file) == 'dir')
                        rrmdir($file);
                    else
                        unlink($file);
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }
    
    /* ==== Class definitions ==== */
    
    /*
    Table column and column definition.
    Column definition uses the following format: (column name),(column size)\n
    E.g.
    NAME,10
    GENDER,1
    */
    class Column {
        public function __construct($name, $size, $offset=0) {
            $this->name   = $name;
            $this->offset = $offset; // column data's position in a table row
            $this->size   = $size;   // column data's size
        }
        
        public static function fromDefinition($definition) {
            $parts = explode(',', $definition); // BOOM
            return new Column(trim($parts[0]), trim(ensure_numeric($parts[1])));
        }
        
        public function getName() {
            return $this->name;
        }
        
        public function setName($name) {
            $this->name = $name;
        }
        
        public function getOffset() {
            return $this->offset;
        }
        
        public function setOffset($offset) {
            $this->offset = $offset;
        }
        
        public function getSize() {
            return $this->size;
        }
        
        /* Constructs a column definition */
        public function getDefinition() {
            return "{$this->name},{$this->size}";
        }
        
        private $name, $offset, $size;
    }
    
    /*
    Low-level table IO operations.
    A table has the following files in its database' directory:
    .dat - Data file (spreadsheet)
    .def - Column definitions
    .exc - Exclusive lock, content is the transaction ID
    .shr - (Directory) shared locks, each file in the directory is named by the transaction ID
    */
    class Table {
        public function __construct($db, $name) {
            $this->db   = $db;
            $this->name = $name;
            $this->open();
            $this->readDefinition();
        }
        
        public function getName() {
            return $this->name;
        }
        
        public function setName($name) {
            $this->name = $name;
        }
        
        public function getDatPath() {
            return $this->db->getDir()."{$this->name}.dat";
        }
        
        public function getDefPath() {
            return $this->db->getDir()."{$this->name}.def";
        }
        
        public function getLineSize() {
            return $this->lineSize;
        }
        
        /* Opens file handle(s). */
        public function open() {
            $this->datFH = fopen($this->getDatPath(), 'rb+');
        }
        
        /* Reads column definitions from .def file. */
        public function readDefinition() {
            $this->sequence = array(); // the sequence (order) of columns
            $this->columns  = array(); // individual column definition
            $this->lineSize = 0;       // size of a line (includes \n)
            foreach (file($this->getDefPath()) as $definition) {
                $column = Column::fromDefinition($definition);
                $column->setOffset($this->lineSize);
                $this->sequence[] = $column->getName();
                $this->lineSize  += $column->getSize();
                $this->columns[$column->getName()] = $column;
            }
            ++$this->lineSize; // the \n counts
        }
        
        /* Counts the number of rows in table, non-integer indicates corrupted table data. */
        public function count() {
            clearstatcache();
            $lines = filesize($this->getDatPath()) / $this->getLineSize();
            return $lines;
        }
        
        /* Adds a new column. */
        public function add($name, $size) {
            db_id($name);
            array_key_exists($name, $this->columns) and die("Column $name already exists");
            // pad each row to leave space for the new column
            if ($this->count() > 0) {
                $this->pad($size);
            }
            $column = new Column($name, $size, $this->lineSize - 1);
            $this->sequence[]     = $name;
            $this->columns[$name] = $column;
            $this->lineSize      += $size;
            // write the definition of the column into the .def file
            write_file($this->getDefPath(), array($column->getDefinition()), true);
        }
        
        /* Removes a column. */
        public function remove($name) {
            array_key_exists($name, $this->columns) or die("Column $name is not found");
            // find and remove the definition from .def file
            $lines = array();
            $definition = $this->columns[$name]->getDefinition();
            foreach (file($this->getDefPath()) as $line) {
                $line = trim($line);
                if ($line != $definition) {
                    $lines[] = $line;
                }
            }
            write_file($this->getDefPath(), $lines);
            $column = $this->columns[$name];
            find_unset($this->sequence, $name);
            unset($this->columns[$name]);
            // remove the data of the removed column
            if ($this->count() > 0) {
                $this->cut($column->getOffset(), $column->getOffset() + $column->getSize());
            }
            $this->lineSize -= $column->getSize();
        }
        
        /* Renames a column. */
        public function alter($old_name, $new_name) {
            db_id($new_name);
            array_key_exists($old_name, $this->columns) or die("Column $old_name is not found");
            array_key_exists($new_name, $this->columns) and die("Column $new_name already exists");
            // find and re-write the definition in .def file
            $lines = array();
            $column = $this->columns[$old_name];
            $definition = $column->getDefinition();
            foreach (file($this->getDefPath()) as $line) {
                $line = trim($line);
                if ($line == $definition) {
                    $size    = $this->columns[$old_name]->getSize();
                    $lines[] = "$new_name,$size";
                } else {
                    $lines[] = $line;
                }
            }
            write_file($this->getDefPath(), $lines);
            $column->setName($new_name);
            find_replace($this->sequence, $old_name, $new_name);
            unset($this->columns[$old_name]);
            $this->columns[$new_name] = $column;
        }
        
        /* Seeks the .dat file handle to the beginning of the row. */
        public function seek($number) {
            $number < $this->count() or die("Row number $number is out of boundary");
            fseek($this->datFH, $number * $this->lineSize, 0);
        }
        
        /* Reads a row, returns an array. */
        public function read($number) {
            $this->seek($number);
            $line = fgets($this->datFH);
            $row = array();
            foreach ($this->sequence as $name) {
                $column     = $this->columns[$name];
                $row[$name] = substr($line, $column->getOffset(), $column->getSize());
            }
            return $row;
        }
        
        /* Seeks the .dat file handle to the row and beginning of the column. */
        public function seekColumn($number, $name) {
            array_key_exists($name, $this->columns) or die("Column $name is not found");
            $this->seek($number);
            fseek($this->datFH, $this->columns[$name]->getOffset(), 1);
        }
        
        /* Inserts a row. */
        public function insert($row) {
            $line = '';
            foreach ($this->sequence as $name) {
                $column = $this->columns[$name];
                $line  .= trim_size(array_key_exists($name, $row) ? $row[$name] : '', $column->getSize());
            }
            $line .= "\n";
            fseek($this->datFH, 0, 2);
            fwrite($this->datFH, $line);
        }
        
        /* Updates the row at the row number. */
        public function update($number, $row) {
            foreach($row as $name=>$val) {
                if (!array_key_exists($name, $this->columns)) {
                    continue;
                }
                $column = $this->columns[$name];
                $this->seekColumn($number, $name);
                fwrite($this->datFH, trim_size($val, $column->getSize()));
            }
        }
        
        /* Deletes the row at the row number. */
        public function delete($number) {
            array_key_exists('~del', $this->columns) or die("Column defition of ~del is missing");
            // set ~del to y
            $this->seekColumn($number, '~del');
            fwrite($this->datFH, trim_size('y', $this->columns['~del']->getSize()));
        }
        
        /* Closes all opened file handles. */
        public function close() {
            clearstatcache();
            fclose($this->datFH);
        }
        
        /* Flushes .dat file. */
        public function flush() {
            $this->close();
            $this->open();
        }
        
        /* Pads spaces to all rows in the table to leave space for a new column. */
        private function pad($padding) {
            $desired_size = $this->lineSize + $padding - 1;
            $lines = array();
            foreach (file($this->getDatPath()) as $line) {
                $lines[] = str_pad(rtrim($line), $desired_size);
            }
            write_file($this->getDatPath(), $lines);
        }
        
        /* Removes data of a removed column. */
        private function cut($begin, $end) {
            $lines = array();
            foreach (file($this->getDatPath()) as $line) {
                $lines[] = rtrim(substr($line, 0, $begin).substr($line, $end, strlen($line) - $begin - 1), "\n");
            }
            write_file($this->getDatPath(), $lines);
        }
        
        public function __desctruct() {
            $this->close();
        }
        
        private $db, $name, $sequence, $columns, $datFH, $lineSize;
    }
    
    /* Database is a directory in file system. */
    class Database {
        public function __construct($dir) {
            global $slash;
            $this->tables = array();
            if (!end_with($dir, $slash)) {
                $dir .= $slash;
            }
            $this->dir = $dir;
            foreach (scandir($dir) as $path) {
                $path_parts = pathinfo($path);
                if ($path_parts['extension'] && $path_parts['extension'] == 'dat') { // it is a table!
                    $table = new Table($this, $path_parts['filename']);
                    $this->tables[$table->getName()] = $table;
                }
            }
        }
        
        public function getDir() {
            return $this->dir;
        }
        
        public function table($name) {
            return $this->tables[$name];
        }
        
        /* Creates a table. */
        public function create($name) {
            db_id($name);
            global $slash, $table_exts, $table_dirs, $db_columns;
            array_key_exists($name, $this->tables) and die("Table $name already exists");
            // create the files and directories for the new table
            foreach ($table_exts as $ext) {
                write_file("{$this->dir}$slash$name.$ext");
            }
            foreach ($table_dirs as $dir) {
                mkdir("{$this->dir}$slash$name.$dir");
            }
            $table = $this->tables[$name] = new Table($this, $name);
            foreach ($db_columns as $column=>$size) {
                $table->add($column, $size);
            }
            return $table;
        }
        
        /* Renames a table. */
        public function rename($old_name, $new_name) {
            global $slash;
            db_id($new_name);
            array_key_exists($old_name, $this->tables) or die("Table $old_name does not exist");
            array_key_exists($new_name, $this->tables) and die("Table $new_name already exists");
            $tab = $this->tables[$old_name];
            $tab->close();
            sleep(1); // Martin Pelletier 05-Feb-2011 06:01
            foreach (scandir($this->dir) as $path) {
                if ($path != '.' && $path != '..') {
                    $path_parts = pathinfo($path);
                    rename("{$this->dir}$path", "{$this->dir}$new_name.{$path_parts['extension']}");
                }
            }
            $tab->setName($new_name);
            $tab->open();
            $this->tables[$new_name] = $tab;
            unset($this->tables[$old_name]);
        }
        
        /* Drops a table. */
        public function drop($name) {
            array_key_exists($name, $this->tables) or die("Table $name does not exist");
            $tab = $this->tables[$name];
            $tab->close();
            foreach (scandir($this->dir) as $path) {
                $path_parts = pathinfo($path);
                echo $path_parts['filename'];
                if ($path_parts['filename'] == $name) {
                    $path = "{$this->dir}$path";
                    if (is_file($path)) {
                        unlink($path);
                    } else {
                        rrmdir($path);
                    }
                }
            }
        }
        
        private $dir, $tables;
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
    echo $tab->count();
    $tab->alter('c2', 'cnew');
    $db->rename('t1', 't2');
    $db->drop('t2');
?>
