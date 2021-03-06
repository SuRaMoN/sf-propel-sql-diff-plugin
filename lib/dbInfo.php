<?php

class dbInfo {
  public $tables = array();
  public $debug = true;
  public $defaultCharset;
  public $defaultCollate;

  function loadFromDb($con) {
    $stmt = $con->prepare("select TABLE_NAME, TABLE_TYPE, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES WHERE table_schema = DATABASE();");
    $stmt->execute();
    $stmt->setFetchMode(PDO::FETCH_BOTH);

    if($stmt->rowCount()==0) return false;
    while($row = $stmt->fetch()) {
        if(strtoupper($row['TABLE_TYPE'])=="BASE TABLE") {
			$columnMeta = array();
			foreach($con->query("show full columns in `{$row['TABLE_NAME']}`")->fetchAll() as $columnMetaInfo) {
				$columnMeta[$columnMetaInfo['Field']] = $columnMetaInfo;
			}
            $this->tables[$row['TABLE_NAME']] = array(
				'collate' => $row['TABLE_COLLATION'],
				'columnMeta' => $columnMeta,
			);
        }
    };

    foreach($this->tables as $table => $null) {
      $stmt = $con->prepare("show create table `".$table."`");
      $stmt->execute();
      $row = $stmt->fetch();
      $create_table = $row[1];
      $this->getTableInfoFromCreate($create_table);
    }

    return true;
  }

  public function loadFromFile($filename) {
    $dump = file_get_contents($filename);
    preg_match_all('/create table ([^\'";]+|\'[^\']*\'|"[^"]*")+;/i', $dump, $matches);
    foreach($matches[0] as $key=>$value) {
      $this->getTableInfoFromCreate($value);
    }
  }

  public function loadAllFilesInDir($dir) {
    $files = sfFinder::type('file')->name('*.schema.sql')->follow_link()->in($dir);
    foreach($files as $file) $this->loadFromFile($file);
  }

  public function getTableInfoFromCreate($create_table) {
    preg_match("/^\s*create table `?([^\s`]+)`?\s+\((.*)\)([^\)]*)$/mis", $create_table, $matches);
    $table = $matches[1];
    $code = $matches[2];
    $table_info = $matches[3];

    $this->tables[$table]['create'] = $create_table;
    $this->tables[$table]['fields'] = array();
    $this->tables[$table]['keys'] = array();
    $this->tables[$table]['fkeys'] = array();

    if(preg_match('/(type|engine)=(\w+)/i', $table_info, $matches)) {
        $this->tables[$table]['type'] = strtolower($matches[2]);
    } else {
        $this->tables[$table]['type'] = '';
    }

    if(preg_match('/ENGINE.*(DEFAULT CHARSET|CHARACTER SET)(=|\s+)["\']?(?P<value>[A-Za-z0-9_]+)/i', $table_info, $matches)) {
        $this->tables[$table]['charset'] = strtolower($matches['value']);
    } elseif(array_key_exists('charset', $this->tables[$table])) {
        $this->tables[$table]['charset'] = $this->tables[$table]['charset'];
    } elseif(null !== $this->defaultCharset) {
        $this->tables[$table]['charset'] = $this->defaultCharset;
    } else {
        $this->tables[$table]['charset'] = '';
    }

    if(preg_match('/ENGINE.*COLLATE(=|\s+)["\']?(?P<value>[A-Za-z0-9_]+)/i', $table_info, $matches)) {
        $this->tables[$table]['collate'] = strtolower($matches['value']);
    } elseif(array_key_exists('collate', $this->tables[$table])) {
        $this->tables[$table]['collate'] = $this->tables[$table]['collate'];
    } elseif(null !== $this->defaultCollate) {
        $this->tables[$table]['collate'] = $this->defaultCollate;
    } else {
        $this->tables[$table]['collate'] = '';
    }

    preg_match_all('/\s*(([^,\'"\(]+|\'[^\']*\'|"[^"]*"|\(([^\(\)]|\([^\(\)]*\))*\))+)\s*(,|$)/', $code, $matches);
    foreach($matches[1] as $key=>$value) {
      $this->getInfoFromPart($table, trim($value));
    }
  }

  public function getInfoFromPart($table, $part) {
    //get fields codes
    if(preg_match("/^`(\w+)`\s+(.*)$/m", $part, $matches)) {
      $fieldname = $matches[1];
      $code = $matches[2];
      $this->tables[$table]['fields'][$fieldname]['code'] = $code;
      $res = preg_match('/' .
        '(?P<type>([^\s(]|(\(([^)\']|(\'[^\']*\'))*\)))+)\s*' .
        '(?P<charset>CHARACTER SET (?P<charsetValue>[^ ]+))?\s*' .
        '(?P<collate>COLLATE (?P<collateValue>[^ ]+))?\s*' .
        '(?P<comment>COMMENT \'(?P<commentValue>.*?)\')?\s*' .
        '(?P<nullable>NOT NULL)?\s*' .
        '(?P<default>default (\'(?P<defaultString>[^\']*)\'|(?P<defaultConst>-?\d+)))?\s*' .
        '(?P<nullable2>NOT NULL)?' .
        '/i', $code, $matches2);
      $type = strtoupper($matches2['type']);
      if($type=='TINYINT') $type = 'TINYINT(4)';
      if($type=='SMALLINT') $type = 'SMALLINT(6)';
      if($type=='INTEGER') $type = 'INT(11)';
      if($type=='BIGINT') $type = 'BIGINT(20)';
      if($type=='BLOB') $type = 'TEXT';   //propel fix, blob is TEXT field with BINARY collation
      $type = str_replace('VARBINARY', 'VARCHAR', $type);
      $type = str_replace('INTEGER', 'INT', $type);
      $this->tables[$table]['fields'][$fieldname] = array(
        'code'    => $code,
        'type'    => $type,
      );
      // null value
      $this->tables[$table]['fields'][$fieldname]['null'] = true;
      if (isset($matches2['nullable']) and $matches2['nullable'] == "NOT NULL")
      {
        $this->tables[$table]['fields'][$fieldname]['null'] = false;
      }
      if (isset($matches2['nullable2']) and $matches2['nullable2'] == "NOT NULL")
      {
        $this->tables[$table]['fields'][$fieldname]['null'] = false;
      }

      if(array_key_exists('collateValue', $matches2) && '' != $matches2['collateValue']) {
        $this->tables[$table]['fields'][$fieldname]['collate'] = $matches2['collateValue'];
      } else if(array_key_exists('columnMeta', $this->tables[$table])) {
        $this->tables[$table]['fields'][$fieldname]['collate'] = $this->tables[$table]['columnMeta'][$fieldname]['Collation'];
      } else {
        $this->tables[$table]['fields'][$fieldname]['collate'] = array_key_exists('collate', $this->tables[$table]) ? $this->tables[$table]['collate'] : '';
      }

      if(array_key_exists('charsetValue', $matches2) && '' != $matches2['charsetValue']) {
        $this->tables[$table]['fields'][$fieldname]['charset'] = $matches2['charsetValue'];
      } else {
        $this->tables[$table]['fields'][$fieldname]['charset'] = array_key_exists('charset', $this->tables[$table]) ? $this->tables[$table]['charset'] : '';
      }

      if($this->isNoTextualType($type)) {
        $this->tables[$table]['fields'][$fieldname]['collate'] = '';
		$this->tables[$table]['fields'][$fieldname]['charset'] = '';
      }

      // default value
      $this->tables[$table]['fields'][$fieldname]['default'] = "";
      if (isset($matches2['defaultConst']) and $matches2['defaultConst'] != "")
      {
        $this->tables[$table]['fields'][$fieldname]['default'] = $matches2['defaultConst'];
      }
      elseif (isset($matches2['defaultString']))
      {
        $this->tables[$table]['fields'][$fieldname]['default'] = $matches2['defaultString'];
      }
    }

    //get key codes
    elseif(preg_match("/^(primary|unique|fulltext)?\s*(key|index)\s+(`(\w+)`\s*)?(.*?)$/mi", $part, $matches)) {
      $keyname = $matches[4];
      $this->tables[$table]['keys'][$keyname]['type'] = $matches[1];
      $this->tables[$table]['keys'][$keyname]['code'] = $matches[5];
      $this->tables[$table]['keys'][$keyname]['fields'] = preg_split('/,\s*/', substr($matches[5], 1, -1));
    }

    elseif(preg_match("/CONSTRAINT\s+\`(.+)\`\s+FOREIGN KEY\s+\(\`(.+)\`\)\s+REFERENCES \`(.+)\` \(\`(.+)\`\)/mi", $part, $matches)) {
      $name = $matches[1];
      $this->tables[$table]['fkeys'][$name] = array(
                        'field' => $matches[2],
                        'ref_table' => $matches[3],
                        'ref_field' => $matches[4],
                        'code' => $part,
      );
      if(preg_match('/ON DELETE (RESTRICT|CASCADE|SET NULL|NO ACTION)/i', $part, $matches)) {
        $this->tables[$table]['fkeys'][$name]['on_delete'] = strtoupper($matches[1]);
      } else {
        $this->tables[$table]['fkeys'][$name]['on_delete'] = 'RESTRICT';
      }
      if(preg_match('/ON UPDATE (RESTRICT|CASCADE|NO ACTION)/i', $part, $matches)) {
        $this->tables[$table]['fkeys'][$name]['on_update'] = strtoupper($matches[1]);
      } else {
        $this->tables[$table]['fkeys'][$name]['on_update'] = 'RESTRICT';
      }
    }

    else {
      throw new Exception("can't parse line '$part' in table $table");
    }
  }

  function isNoTextualType($tableType) {
    $tableType = strtolower($tableType);
    return in_array($tableType, array('date', 'timestamp', 'datetime')) || preg_match('/^(float|longblob|tinyint|bigint|int|decimal|double)(\(.*\))?$/', $tableType);
  }

  function tableSupportsFkeys($tabletype) {
      return !in_array($tabletype, array('myisam', 'ndbcluster'));
  }


  private function getTableTypeDiff($db_info2) {
    $diff_sql = "";
    foreach($db_info2->tables as $tablename=>$tabledata) {
      if(empty($this->tables[$tablename])) continue;
      //change table type
      if($this->tables[$tablename] && $tabledata['type']!=$this->tables[$tablename]['type']) {
        $diff_sql .= "ALTER TABLE `$tablename` engine={$tabledata['type']};\n";
      }
      if(
		  $this->tables[$tablename] && $tabledata['charset'] != ''
		  && (
		  	$tabledata['charset']!=$this->tables[$tablename]['charset']
			|| ($tabledata['collate'] != '' && $tabledata['collate']!=$this->tables[$tablename]['collate'])
		 )) {
        $diff_sql .= "ALTER TABLE `$tablename` DEFAULT CHARACTER SET {$tabledata['charset']}";
		if($tabledata['collate'] != '' && $tabledata['collate']!=$this->tables[$tablename]['collate']) {
          $diff_sql .= " COLLATE {$tabledata['collate']}";
		}
		$diff_sql.=";\n";
      }
    }
    return $diff_sql;
  }


  function getDiffWith(dbInfo $db_info2) {

    $diff_sql = '';

    $diff_sql .= $this->getTableTypeDiff($db_info2);

    //adding columns, indexes, etc
    foreach($db_info2->tables as $tablename=>$tabledata) {

      //check for new table
      if(!isset($this->tables[$tablename])) {
        $diff_sql .= "\n".$db_info2->tables[$tablename]['create']."\n";
        continue;
      }

      //check for new field
      foreach($tabledata['fields'] as $field=>$fielddata) {
        $mycode = $fielddata['code'];
        $othercode = @$this->tables[$tablename]['fields'][$field]['code'];
        if($mycode and !$othercode) {
          $diff_sql .= "ALTER TABLE `$tablename` ADD `$field` $mycode;\n";
        };
      };

      //check for new index
      if($tabledata['keys']) foreach($tabledata['keys'] as $field=>$fielddata) {
        $mycode = $fielddata['code'];
        $otherdata = @$this->tables[$tablename]['keys'][$field];
        $othercode = @$otherdata['code'];
        if($mycode and !$othercode) {
          if($fielddata['type']=='PRIMARY') {
            $diff_sql .= "ALTER TABLE `$tablename` ADD PRIMARY KEY $mycode;\n";
          } else {
            $diff_sql .= "ALTER TABLE `$tablename` ADD {$fielddata['type']} INDEX `$field` $mycode;\n";
          }
        };
      };

      //check for new foreign key
      if($tabledata['fkeys'] && $this->tableSupportsFkeys($tabledata['type'])) {
        foreach($tabledata['fkeys'] as $fkeyname=>$data) {
          $mycode = $data['code'];
          $otherfkname = $this->get_fk_name_by_field($tablename, $data['field']);
          $othercode = @$this->tables[$tablename]['fkeys'][$otherfkname]['code'];
          if($mycode && !$othercode) {
            $diff_sql .= "ALTER TABLE `$tablename` ADD {$mycode};\n";
          };
        }
      };
    };

    //modifying and deleting columns, indexes, etc
    foreach($this->tables as $tablename=>$tabledata) {

      //check table exists
      if(!isset($db_info2->tables[$tablename])) {
        $diff_sql .= "DROP TABLE `$tablename`;\n";
        continue;
      }

      //drop, alter foreign key
      if($tabledata['fkeys'] && $this->tableSupportsFkeys($tabledata['type'])) {
        foreach($tabledata['fkeys'] as $fkeyname=>$data) {
          $mycode = $data['code'];
          $otherfkname = $db_info2->get_fk_name_by_field($tablename, $data['field']);
          $othercode = @$db_info2->tables[$tablename]['fkeys'][$otherfkname]['code'];
          if($mycode and !$othercode) {
            $diff_sql .= "ALTER TABLE `$tablename` DROP FOREIGN KEY `$fkeyname`;\n";
          } else {
            $data2 = $db_info2->tables[$tablename]['fkeys'][$otherfkname];
            if ($data['ref_table'] != $data2['ref_table'] ||
            $data['ref_field'] != $data2['ref_field'] ||
            $data['on_delete'] != $data2['on_delete'] ||
            $data['on_update'] != $data2['on_update']) {
              if($this->debug) {
                $diff_sql .= "/* old definition: $mycode\n   new definition: $othercode */\n";
              }
              $diff_sql .= "ALTER TABLE `$tablename` DROP FOREIGN KEY `$fkeyname`;\n";
              $diff_sql .= "ALTER TABLE `$tablename` ADD {$othercode};\n";
            }
          };
        };
      }

      //drop, alter index
      if($tabledata['keys']) foreach($tabledata['keys'] as $field=>$fielddata) {
        $otherdata = @$db_info2->tables[$tablename]['keys'][$field];
        $ind_name = @$otherdata['type']=='PRIMARY'?'PRIMARY KEY':"{$otherdata['type']} INDEX";
        if($fielddata['code'] and !$otherdata['code']) {
          if($fielddata['type']=='PRIMARY') {
            $diff_sql .= "ALTER TABLE `$tablename` DROP PRIMARY KEY;\n";
          } else {
            $diff_sql .= "ALTER TABLE `$tablename` DROP INDEX $field;\n";
          }
        } elseif($fielddata['fields'] != $otherdata['fields'] or $fielddata['type']!=$otherdata['type']) {
          if($this->debug) {
            $diff_sql .= "/* old definition: {$fielddata['code']}\n   new definition: {$otherdata['code']} */\n";
          }
          if($fielddata['type']=='PRIMARY') {
            $diff_sql .= "ALTER TABLE `$tablename` DROP PRIMARY KEY,";
          } else {
            $diff_sql .= "ALTER TABLE `$tablename` DROP INDEX $field,";
          }
          $diff_sql .= "        ADD $ind_name ".($field?"`$field`":"")." {$otherdata['code']};\n";
        };
      };

      //drop, alter field
      foreach($tabledata['fields'] as $field=>$fielddata) {
        $mycode = $fielddata['code'];
        $otherdata = @$db_info2->tables[$tablename]['fields'][$field];
        $othercode = @$otherdata['code'];
        if($mycode and !$othercode) {
          $diff_sql .= "ALTER TABLE `$tablename` DROP `$field`;\n";
        } elseif($fielddata['type'] != $otherdata['type']
        || $fielddata['null'] != $otherdata['null']
        || $fielddata['charset'] != $otherdata['charset']
        || $fielddata['collate'] != $otherdata['collate']
        || $fielddata['default'] != $otherdata['default']   ) {
          if($this->debug) {
            $diff_sql .= "/* old definition: $mycode\n   new definition: $othercode */\n";
          }
          $diff_sql .= "ALTER TABLE `$tablename` CHANGE `$field` `$field` $othercode;\n";
        };
      };
    };

    return $diff_sql;
  }

  private function get_fk_name_by_field($tablename, $fieldname) {
    if($this->tables[$tablename]['fkeys']) {
      foreach($this->tables[$tablename]['fkeys'] as $fkeyname=>$data) {
        if($data['field'] == $fieldname) return $fkeyname;
      }
    };
    return null;
  }

  public function executeSql($sql, $connection) {
      $queries = $this->explodeSql($sql);
      foreach($queries as $query) {
        $this->executeQuery($query, $connection);
      }
  }

  public function explodeSql($sql) {
    $result = array();
    preg_match_all('/([^\'";]+|\'[^\']*\'|"[^"]*")+;/i', $sql, $matches);
    foreach($matches[0] as $query) {
      $result[] = $query;
    }
    return $result;
  }

  public function executeQuery($query, $connection) {
    $stmt = $connection->prepare($query);
    $stmt->execute();
    return $stmt;
  }


};
?>
