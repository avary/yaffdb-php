<?php
    /* 
    ==== Coding Style ====
    Indentation and brackets: K&R.
    camoCase for OOP related data/behaviour.
    underscore_seperated for non-OOP/external data/behaviour.
    Inline comments use // and punctuations but do not end with full-stop, first letter is in lower-case.
    Single-line document comments use slash**slash and punctuations but do not end with full-stop, first letter is in upper-case.
    Multi-line comments use slash**slash and punctuations, first letter is in upper-case.
    */
    
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
    
    /* Truncates or trims the string to the desired size */
    function trim_size($str, $size) {
        if ($size - strlen($str) > 0) {
            return str_pad($str, $size);
        } else {
            return substr($str, 0, $size);
        }
    }
    
    /* Looks for a value in an array and remove it */
    function find_unset(&$array, $val) {
        foreach ($array as $i=>$v) {
            if ($v == $val) {
                unset($array[$i]);
            }
        }
    }
    
    /* Tests if the string ends with the ending */
    function end_with($str, $ending) { // MrHus@StackOverflow
        $length = strlen($ending);
        $start  = $length * -1;
        return (substr($str, $start) === $ending);
    }
    
    /* ==== Class definitions ==== */
    
    /*
    Table column and table column definition.
    Definition is formatted as: (column name),(size)\n
    */
    class Column {
        public function __construct($name, $size, $offset=0) {
            $this->name = $name;
            $this->offset = $offset; // column data's position in a table row
            $this->size = $size; // column data's size
        }
        
        public static function fromDefinition($definition) {
            $parts = explode(',', trim($definition)); // BOOM
            return new Column($parts[0], $parts[1]);
        }
        
        public function getName() {
            return $this->name;
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
    .exc - Exclusive lock
    .shr - (Directory) shared locks
    */
    class Table {
        public function __construct($db, $name) {
            $this->db = $db;
            $this->name = $name;
            $this->openFileHandle();
            $this->readDefinition();
        }
        
        public function getName() {
            return $this->name;
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
        
        public function openFileHandle() {
            $this->datFH = fopen($this->getDatPath(), 'rb+');
        }
        
        public function readDefinition() {
            $this->sequence = array();
            $this->columns = array();
            $this->lineSize = 0;;
            foreach (file($this->getDefPath()) as $definition) {
                $column = Column::fromDefinition($definition);
                $column->setOffset($this->lineSize);
                $this->sequence[] = $column->getName();
                $this->columns[$column->getName()] = $column;
                $this->lineSize += $column->getSize();
            }
            ++$this->lineSize;
        }
        
        public function count() {
            clearstatcache();
            return filesize($this->getDatPath()) / $this->getLineSize();
        }
        
        public function add($name, $size) {
            array_key_exists($name, $this->columns) and die("Column $name already exists");
            if ($this->count() > 0) {
                $this->padLines($size);
            }
            $column = new Column($name, $size, $this->lineSize - 1);
            $this->sequence[] = $name;
            $this->columns[$name] = $column;
            $this->lineSize += $size;
            write_file($this->getDefPath(), array($column->getDefinition()), true);
        }
        
        public function remove($name) {
            array_key_exists($name, $this->columns) or die("Column $name is not found");
            $lines = array();
            $definition = $this->columns[$name]->getDefinition();
            foreach (file($this->getDefPath()) as $line) {
                if ($line == $definition) {
                    $lines[] = $line;
                }
            }
            write_file($this->getDefPath(), $lines);
            $column = $this->columns[$name];
            find_unset($this->sequence, $name);
            unset($this->columns[$name]);
            if ($this->count() > 0) {
                $this->cut($column->getOffset(), $column->getOffset() + $column->getSize());
            }
            $this->lineSize -= $column->getSize();
        }
        
        public function rename($old_name, $new_name) {
            array_key_exists($old_name, $this->columns) or die("Column $old_name is not found");
            array_key_exists($new_name, $this->columns) and die("Column $new_name already exists");
            $lines = array();
            $definition = $this->columns[$name]->getDefinition();
            foreach (file($this->getDefPath()) as $line) {
                if ($line == $definition) {
                    $size = $this->columns[$old_name]->getSize();
                    $lines[] = "$new_name,$size";
                } else {
                    $lines[] = $line;
                }
            }
            write_file($this->getDefPath(), $lines);
        }
        
        public function seek($number) {
            $number < $this->count() or die("Row number $number is out of boundary");
            fseek($this->datFH, $number * $this->lineSize, 0);
        }
        
        public function read($number) {
            $this->seek($number);
            $line = fgets($this->datFH);
            $row = array();
            foreach ($this->sequence as $name) {
                $column = $this->columns[$name];
                $row[$name] = substr($line, $column->getOffset(), $column->getSize());
            }
            return $row;
        }
        
        public function seekColumn($number, $name) {
            array_key_exists($name, $this->columns) or die("Column $name is not found");
            $this->seek($number);
            fseek($this->datFH, $this->columns[$name]->getOffset(), 1);
        }
        
        public function insert($row) {
            $line = '';
            foreach ($this->sequence as $name) {
                $column = $this->columns[$name];
                $line .= trim_size(array_key_exists($name, $row) ? $row[$name] : '', $column->getSize());
            }
            $line .= "\n";
            fseek($this->datFH, 0, 2);
            fwrite($this->datFH, $line);
        }
        
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
        
        public function delete($number) {
            array_key_exists('~del', $this->columns) or die("Column defition of ~del is missing");
            $this->seekColumn($number, '~del');
            fwrite($this->datFH, trim_size('y', $this->columns['~del']->getSize()));
        }
        
        public function flush() {
            clearstatcache();
            fclose($this->datFH);
            $this->openFileHandle();
        }
        
        private function padLines($padding) {
            $desired_size = $this->lineSize + $padding - 1;
            $lines = array();
            foreach (file($this->getDatPath()) as $line) {
                $lines[] = str_pad(rtrim($line), $desired_size);
            }
            write_file($this->getDatPath(), $lines);
        }
        
        private function cut($begin, $end) {
            $lines = array();
            foreach (file($this->getDatPath()) as $line) {
                $lines[] = rtrim(substr($line, 0, $begin).substr($line, $end, strlen($line) - $begin - 1), "\n");
                echo "Outputing:\n";
                var_dump(substr($line, 0, $begin).substr($line, $end, strlen($line) - $begin - 1));
            }
            write_file($this->getDatPath(), $lines);
        }
        
        public function __desctruct() {
            fclose($datFH);
        }
        
        private $db, $name, $sequence, $columns, $datFH, $lineSize;
    }
    
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
        
        public function create($name) {
            global $slash, $table_exts, $table_dirs, $db_columns;
            array_key_exists($name, $this->tables) and die("Table $name already exists");
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
    // $tab->remove('c3');
    $tab->insert(array('c3'=>'bbb'));
    $tab->insert(array('c3'=>'ccc'));
    $tab->delete(0);
    $tab->update(1, array('c1'=>'new!'));
    echo $tab->count();
?>
