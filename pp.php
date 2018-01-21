<?php

class PrettyPrint
{
  static function format($callback, &$output = '', $maxwidth = 79, $newline = "\n", $genspace = null) {
    $q = new PrettyPrint($output, $maxwidth, $newline, $genspace);
    $callback($q);
    $q->flush();
    return $output;
  }

  static function singlelineFormat($callback, $output = '', $maxwidth = null, $newline = null, $genspace = null) {
    $q = new SingleLine($output);
    $callback($q);
    return $output;
  }

  function __construct(&$output = '', $maxwidth = 79, $newline = "\n", $genspace = null) {
    $this->output = &$output;
    $this->maxwidth = $maxwidth;
    $this->newline = $newline;
    $this->genspace = $genspace ?: function($n) {
      $s = '';
      for ($i = 0; $i < $n; $i++) {
        $s .= ' ';
      }
      return $s;
    };

    $this->outputWidth = 0;
    $this->bufferWidth = 0;
    $this->buffer = [];

    $rootGroup = new Group(0);
    $this->groupStack = [$rootGroup];
    $this->groupQueue = new GroupQueue($rootGroup);
    $this->indent = 0;
  }

  public $output;
  public $maxwidth;
  public $newline;
  public $genspace;
  public $indent;
  public $groupQueue;

  function currentGroup() {
    return $this->groupStack[count($this->groupStack) - 1];
  }

  function breakOutmostGroups() {
    while ($this->maxwidth < $this->outputWidth + $this->bufferWidth) {
      $group = $this->groupQueue->deq();
      if ($group === null) {
        return;
      }

      while (!empty($group->breakables)) {
        $data = array_shift($this->buffer);
        $this->outputWidth = $data->output($this->output, $this->outputWidth);
        $this->bufferWidth -= $data->width;
      }

      while (!empty($this->buffer) && ($this->buffer[0] instanceof Text)) {
        $text = array_shift($this->buffer);
        $this->outputWidth = $text->output($this->output, $this->outputWidth);
        $this->bufferWidth -= $text->width;
      }
    }
  }

  function text($obj, $width = null) {
    $width = $width ?: strlen($obj);
    if (empty($this->buffer)) {
      $this->output .= $obj;
      $this->outputWidth += $width;
    } else {
      $text = $this->buffer[count($this->buffer) - 1];
      if (!($text instanceof Text)) {
        $text = new Text();
        $this->buffer []= $text;
      }
      $text->add($obj, $width);
      $this->bufferWidth += $width;
      $this->breakOutmostGroups();
    }
  }

  function fillBreakable($sep = ' ', $width = null) {
    $width = $width ?: strlen($sep);
    $this->group(function() use($sep, $width) {
      $this->breakable($sep, $width);
    });
  }

  function breakable($sep = ' ', $width = null) {
    $width = $width ?: strlen($sep);
    $group = $this->groupStack[count($this->groupStack) - 1];
    if ($group->break) {
      $this->flush();
      $this->output .= $this->newline;
      $genspace = $this->genspace;
      $this->output .= $genspace($this->indent);
      $this->outputWidth = $this->indent;
      $this->bufferWidth = 0;
    } else {
      $this->buffer []= new Breakable($sep, $width, $this);
      $this->bufferWidth += $width;
      $this->breakOutmostGroups();
    }
  }

  function group($callback, $indent = 0, $openObj = '', $closeObj = '', $openWidth = null, $closeWidth = null) {
    $openWidth = $openWidth ?: strlen($openObj);
    $closeWidth = $closeWidth ?: strlen($closeObj);
    $this->text($openObj, $openWidth);
    $this->groupSub(function() use($callback, $indent) {
      $this->nest($callback, $indent);
    });
    $this->text($closeObj, $closeWidth);
  }

  function groupSub($callback) {
    $group = new Group($this->groupStack[count($this->groupStack) - 1]->depth + 1);
    $this->groupStack []= $group;
    $this->groupQueue->enq($group);
    try {
      $callback();
    } finally {
      array_pop($this->groupStack);
      if (empty($group->breakables)) {
        $this->groupQueue->delete($group);
      }
    }
  }

  function nest($callback, $indent) {
    $this->indent += $indent;
    try {
      $callback();
    } finally {
      $this->indent -= $indent;
    }
  }

  function flush() {
    foreach ($this->buffer as $data) {
      $this->outputWidth = $data->output($this->output, $this->outputWidth);
    }
    $this->buffer = [];
    $this->bufferWidth = 0;
  }
}

class Text
{
  function __construct() {
    $this->objs = [];
    $this->width = 0;
  }

  public $width;

  function output(&$out, $outputWidth) {
    foreach ($this->objs as $obj) {
      $out .= $obj;
    }
    return $outputWidth + $this->width;
  }

  function add($obj, $width) {
    $this->objs []= $obj;
    $this->width += $width;
  }
}

class Breakable
{
  function __construct($sep, $width, $q) {
    $this->obj = $sep;
    $this->width = $width;
    $this->pp = $q;
    $this->indent = $q->indent;
    $this->group = $q->currentGroup();
    $this->group->breakables []= $this;
  }

  public $obj;
  public $width;
  public $indent;

  function output(&$out, $outputWidth) {
    array_shift($this->group->breakables);
    if ($this->group->break) {
      $out .= $this->pp->newline;
      $genspace = $this->pp->genspace;
      $out .= $genspace($this->indent);
      return $this->indent;
    } else {
      if (empty($this->group)) {
        $this->pp->groupQueue->delete($this->group);
      }
      $out .= $this->obj;
      return $outputWidth + $this->width;
    }
  }
}

class Group
{
  function __construct($depth) {
    $this->depth = $depth;
    $this->breakables = [];
    $this->break = false;
  }

  public $depth;
  public $breakables;
  public $break;
}

class GroupQueue
{
  function __construct(...$groups) {
    $this->queue = [];
    foreach ($groups as $g) {
      $this->enq($g);
    }
  }

  function enq($group) {
    $depth = $group->depth;
    while ($depth >= count($this->queue)) {
      $this->queue []= [];
    }
    $this->queue[$depth] []= $group;
  }

  function deq() {
    foreach ($this->queue as &$gs) {
      for ($i = count($gs) - 1; $i >= 0; $i--) {
        if (!empty($gs[$i]->breakables)) {
          $group = array_slice($gs, $i, 1)[0];
          $group->break = true;
          return $group;
        }
      }
      foreach ($gs as $group) {
        $group->break = true;
      }
      $gs = [];
    }
    return null;
  }

  function delete($group) {
    $queueEntry = $this->queue[$group->depth];
    for ($i = 0; $i < count($queueEntry); $i++) {
      if ($queueEntry[$i] == $group) {
        array_slice($queueEntry, $i, 1);
        return;
      }
    }
  }
}

class PP extends PrettyPrint
{
  function __construct(&$output = '', $maxwidth = 79, $newline = "\n", $genspace = null) {
    parent::__construct($output, $maxwidth, $newline, $genspace);
  }

  static function prettyPrint($obj, &$out = null, $width = 79) {
    $q = new PP($out, $width);
    //$q->guard_inspect_key(function() use($q, $obj) {
      $q->pp($obj);
    //});
    $q->flush();
    $out .= "\n";
  }

  // TODO
  // static function singleline_pp() {
  // }
  
  private function pp($obj) {
    // TODO: Other built-in data types? stdClass
    // TODO: XML, JSON, common representations?
    // TODO: Methods?
    $this->group(function() use($obj) {
      if (is_array($obj)) {
        $isHash = array_keys($obj) !== range(0, count($obj) - 1);
        if ($isHash) {
          $this->ppHash($obj);
        } else {
          $this->ppArray($obj);
        }
      } else if (is_numeric($obj)) {
        $this->text("$obj");
      } else if (is_bool($obj)) {
        $this->text($obj ? 'true' : 'false');
      } else if (is_string($obj)) {
        $this->ppString($obj);
      } else if ($obj === null) {
        $this->text('null');
      } else if (is_object($obj)) {
        $this->ppObject($obj);
      } else {
        $this->text("$obj");
      }
    });
  }

  private function ppString($str) {
    $escaped = [
      "\n" => '\n',
      "\r" => '\r',
      "\t" => '\t',
      "\v" => '\v',
      "\e" => '\e',
      "\f" => '\f',
      "\\" => '\\\\',
      "\$" => '\$',
      '"' => '\"'
    ];
    $str = preg_replace_callback("/[\n\r\t\v\e\f\$\"\\\\]/", function($match) use($escaped) {
      return $escaped[$match[0]];
    }, $str);
    $this->text("\"$str\"");
  }

  private function ppObject($obj) {
    // TODO: Print address?
    $this->group(function() use($obj) {
      $this->seplist(function($k, $v) {
        $this->breakable();
        $this->text($k);
        $this->text('=');
        $this->group(function() use($v) {
          $this->breakable('');
          $this->pp($v);
        }, 1);
      }, $obj, function() { $this->text(','); }, function($obj, $callback) {
        $reflection = new ReflectionObject($obj);
        foreach ($reflection->getProperties() as $prop) {
          $prop->setAccessible(true);
          $callback($prop->getName(), $prop->getValue($obj));
        }

        /*
         * TODO: Support calling get*() methods?
        foreach ($reflection->getMethods() as $method) {
          $name = $method->getName();
          if (preg_match('/^get[^a-z](.*)/', $name) && $method->getNumberOfParameters() == 0) {
            $method->setAccessible(true);
            $value = $method->invoke($obj);
            $callback("$name()", $value);
          }
        }
         */
      });
    }, 1, '#<' . get_class($obj), '>');
  }

  private function ppArray($arr) {
    $this->group(function() use($arr) {
      $this->seplist(function($v) {
        $this->pp($v);
      }, $arr);
    }, 1, '[', ']');
  }

  private function ppHash($hash) {
    $this->group(function() use($hash) {
      $this->seplist(function($k, $v) {
        $this->group(function() use($k, $v) {
          $this->pp($k);
          $this->text('=>');
          $this->group(function() use($v) {
            $this->breakable('');
            $this->pp($v);
          }, 1);
        });
      }, $hash, null, function($obj, $callback) {
        foreach ($obj as $k => $v) {
          $callback($k, $v);
        }
      });
    }, 1, '[', ']');
  }

  private function commaBreakable() {
    $this->text(',');
    $this->breakable();
  }

  private function seplist($callback, $list, $sep = null, $iter = null) {
    $sep = $sep ?: function() { $this->commaBreakable(); };
    $iter = $iter ?: function($obj, $callback) {
      foreach ($obj as $v) {
        $callback($v);
      }
    };
    $first = true;
    $iter($list, function(...$v) use($callback, $sep, &$first) {
      if ($first) {
        $first = false;
      } else {
        $sep();
      }
      $callback(...$v);
    });
  }
}

function pp(...$objs) {
  $out = '';
  foreach ($objs as $obj) {
    PP::prettyPrint($obj, $out);
  }
  echo($out);
  if (count($objs) <= 1) {
    return $objs[0];
  } else {
    return $objs;
  }
}

function ppd(...$objs) {
  pp(...$objs);
  die();
}
