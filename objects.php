<?php
// vim: set tabstop=4 shiftwidth=4 fdm=marker:

require_once 'display.php';

require_once 'compat.php';
// {{{ Helper functions

// {{{ getFLashDimensions - Find the height and width of the given flash string
function getFlashDimensions($font,$title,$size) {
	$f = new SWFFont($font);
	$t = new SWFText();
	$t->setFont($f);
	$t->setHeight($size);
	$dx = $t->getWidth($title) + 10;
	$dy = $size+10;
	return array($dx,$dy);
}
// }}}

// {{{ my_new_pdf_page($pdf, $x, $y)
function my_new_pdf_page($pdf, $x, $y) {
	global $pdf_x, $pdf_y, $page_number;

	$page_number++;
	pdf_begin_page($pdf, $pdf_x, $pdf_y);
	// Having the origin in the bottom left corner confuses the
	// heck out of me, so let's move it to the top-left.
	pdf_translate($pdf,0,$pdf_y);
	pdf_scale($pdf, 1, -1);   // Reflect across horizontal axis
	pdf_set_value($pdf,"horizscaling",-100); // Mirror
}
// }}}

// {{{ my_pdf_page_number($pdf)
function my_pdf_page_number($pdf) {
	global $pdf_x, $pdf_y, $pdf_cx, $pdf_cy, $page_number, $page_index, $pdf_font;

	if(isset($page_index[$page_number]) && $page_index[$page_number] == 'titlepage') return;
	pdf_set_font($pdf, $pdf_font, -10, 'winansi');
	$dx = pdf_stringwidth($pdf,"- $page_number -");
	$x = (int)($pdf_x/2 - $dx/2);
	$pdf_cy = pdf_get_value($pdf, "texty");
	pdf_show_xy($pdf, "- $page_number -", $x, $pdf_y-20);
}
// }}}

/* {{{ my_pdf_paginated_code($pdf, $data, $x, $y, $tm, $bm, $lm, $rm, $font, $fs) {

   Function displays and paginates a bunch of text.  Wordwrapping is also
   done on long lines.  Top-down coordinates and a monospaced font are assumed.

     $data = text to display
     $x    = width of page
     $y    = height of page
     $tm   = top-margin
     $bm   = bottom-margin
     $lm   = left-margin
     $rm   = right-margin
     $font = font name
     $fs   = font size
*/
function my_pdf_paginated_code($pdf, $data, $x, $y, $tm, $bm, $lm, $rm, $font, $fs) {
	$data = strip_markups($data);	
	pdf_set_font($pdf, $font, $fs, 'winansi');	
	$cw = pdf_stringwidth($pdf,'m'); // Width of 1 char - assuming monospace
	$linelen = (int)(($x-$lm-$rm)/$cw);  // Number of chars on a line

	$pos = $i = 0;
	$len = strlen($data);
	pdf_set_text_pos($pdf, $lm, $tm);
	
	$np = true;
	while($pos < $len) {
		$nl = strpos(substr($data,$pos),"\n");
		if($nl===0) {
			if($np) { pdf_show($pdf, ""); $np = false; }
			else pdf_continue_text($pdf, "");
			$pos++;
			continue;
		}
		if($nl!==false) $ln = substr($data,$pos,$nl);
		else { 
			$ln = substr($data,$pos);
			$nl = $len-$pos;
		}
		if($nl>$linelen) { // Line needs to be wrapped
			$ln = wordwrap($ln,$linelen);
			$out = explode("\n", $ln);
		} else {
			$out = array($ln);	
		}
		foreach($out as $l) {
			$l = str_replace("\t",'    ',$l);  // 4-space tabs - should probably be an attribute
			if($np) { pdf_show($pdf, $l); $np = false; }
			else pdf_continue_text($pdf, $l);
		}
		$pos += $nl+1;
		if(pdf_get_value($pdf, "texty") >= ($y-$bm)) {
			my_pdf_page_number($pdf);
			pdf_end_page($pdf);
			my_new_pdf_page($pdf, $x, $y);

			pdf_set_font($pdf, $font, $fs, 'winansi');	
			pdf_set_text_pos($pdf, $lm, 60);
			$np = true;
		}
		
	}
}
// }}}

function format_tt($arg) {
  return("<tt>".str_replace(' ', '&nbsp;', $arg[1])."</tt>");
}

/* {{{ string markup_text($str)
	*word*        Bold
	_word_        underline
	%word%        monospaced word (ie. %function()%)
	~word~	      italics
	|rrggbb|word| Colour a word
	^N^           Superscript
	@N@           Subscript
	**word**      Blink
	#id#          Entity
*/
function markup_text($str) {
  $ret = $str;
#	$ret = preg_replace('/\*([\S ]+?)([^\\\])\*/','<strong>\1\2</strong>',$str);
	$ret = preg_replace('/#([[:alnum:]]+?)#/','&\1;',$ret);
	$ret = preg_replace('/\b_([\S ]+?)_\b/','<u>\1</u>',$ret);

	// blink
	$ret = str_replace('\*',chr(1),$ret);
	$ret = preg_replace('/\*\*([\S ]+?)\*\*/','<blink>\1</blink>',$ret);
	$ret = str_replace(chr(1),'\*',$ret);

	// bold
	$ret = str_replace('\*',chr(1),$ret);
	$ret = preg_replace('/\*([\S ]+?)\*/','<strong>\1</strong>',$ret);
	$ret = str_replace(chr(1),'\*',$ret);

	// italics
	$ret = str_replace('\~',chr(1),$ret);
	$ret = preg_replace('/~([\S ]+?)~/','<i>\1</i>',$ret);
	$ret = str_replace(chr(1),'\~',$ret);

	// monospace font
	$ret = str_replace('\%',chr(1),$ret);
	$ret = preg_replace_callback('/%([\S ]+?)%/', 'format_tt', $ret);
	$ret = str_replace(chr(1),'%',$ret);

	// Hack by arjen: allow more than one word to be coloured
	$ret = preg_replace('/\|([0-9a-fA-F]+?)\|([\S ]+?)\|/','<font color="\1">\2</font>',$ret);
	$ret = preg_replace('/\^([[:alnum:]]+?)\^/','<sup>\1</sup>',$ret);
	$ret = preg_replace('/\@([[:alnum:]]+?)\@/','<sub>\1</sub>',$ret);
	// Quick hack by arjen: BR/ and TAB/ pseudotags from conversion
	$ret = preg_replace('/BR\//','<BR/>',$ret);
	$ret = preg_replace('/TAB\//',' ',$ret);

	$ret = preg_replace('/([\\\])([*#_|^@%])/', '\2', $ret);

	return $ret;
}
// }}}

function add_line_numbers($text)
{
    $lines = preg_split ('!$\n!m', $text);
    $lnwidth = strlen(count($lines));
    $format = '%'.$lnwidth."d: %s\n";
    $lined_text = '';
    while (list ($num, $line) = each ($lines)) {
            $lined_text .= sprintf($format, $num + 1, $line);
    }
    return $lined_text;
}


// {{{ strip_markups
function strip_markups($str) {

	$ret = str_replace('\*',chr(1),$str);
	$ret = preg_replace('/\*([\S ]+?)\*/','\1',$ret);
	$ret = str_replace(chr(1),'\*',$ret);

	$ret = preg_replace('/\b_([\S ]+?)_\b/','\1',$ret);
	$ret = str_replace('\%',chr(1),$ret);
	$ret = preg_replace('/%([\S ]+?)%/','\1',$ret);
	$ret = str_replace(chr(1),'\%',$ret);

	$ret = preg_replace('/~([\S ]+?)~/','\1',$ret);
	// Hack by arjen: allow more than one word to be coloured
	$ret = preg_replace('/\|([0-9a-fA-F]+?)\|([\S ]+?)\|/','\2',$ret);
	$ret = preg_replace('/\^([[:alnum:]]+?)\^/','^\1',$ret);
	$ret = preg_replace('/\@([[:alnum:]]+?)\@/','_\1',$ret);
	$ret = preg_replace('/~([\S ]+?)~/','<i>\1</i>',$ret);
	// Quick hack by arjen: BR/ and TAB/ pseudotags from conversion
	$ret = preg_replace('/BR\//','<BR/>',$ret);
	$ret = preg_replace('/TAB\//','',$ret);
	$ret = preg_replace('/([\\\])([*#_|^@%])/', '\2', $ret);
	return $ret;
} 
// }}}

// }}}

	// {{{ Presentation List Classes
	class _tag {
		function display() {
			global $mode;
			
			$class = get_class($this);
			$mode->$class($this);
		}
	}
	
	class _presentation extends _tag {
		function _presentation() {
			global $baseFontSize, $jsKeyboard, $baseDir;

			$this->title = 'No Title Text for this presentation yet';
			$this->navmode  = 'html';
			$this->mode  = 'html';
			$this->navsize=NULL; // nav bar font size
			$this->template = 'php';
			$this->jskeyboard = $jsKeyboard;
			$this->logo1 = 'images/php_logo.gif';
			$this->logo2 = NULL;
			$this->basefontsize = $baseFontSize;
			$this->backgroundcol = false;
			$this->backgroundfixed = false;
			$this->backgroundimage = false;
			$this->backgroundrepeat = false;
			$this->navbarbackground = 'url(images/trans.png) transparent fixed';
			$this->navbartopiclinks = true;
			$this->navbarheight = '6em';
			$this->examplebackground = '#cccccc';
			$this->outputbackground = '#eeee33';
			$this->shadowbackground = '#777777';
			$this->stylesheet = 'css.php';
			$this->logoimage1url = 'http://' . $_SERVER['HTTP_HOST'] . $baseDir . '/index.php';
			$this->animate=false;
		}
	}

	class _pres_slide extends _tag {
		function _pres_slide() {
			$this->filename = '';
		}
	}
	// }}}

	// {{{ Slide Class
	class _slide extends _tag {

		function _slide() {
			$this->title = 'No Title Text for this slide yet';
			$this->titleSize  = "3em";
			$this->titleColor = '#ffffff';
			$this->navColor = '#EFEF52';
			$this->navSize  = "2em";
			$this->titleAlign = 'center';
			$this->titleFont  = 'fonts/Verdana.fdb';
			$this->template   = 'php';
			$this->layout = '';
		}

	}
	// }}}

	// {{{ Blurb Class
	class _blurb extends _tag {

		function _blurb() {
			$this->font  = 'fonts/Verdana.fdb';
			$this->align = 'left';
			$this->talign = 'left';
			$this->fontsize     = '2.66em';
			$this->marginleft   = '1em';
			$this->marginright  = '1em';
			$this->margintop    = '0.2em';	
			$this->marginbottom = '0em';	
			$this->title        = '';
			$this->titlecolor   = '#000000';
			$this->text         = '';
			$this->textcolor    = '#000000';
			$this->effect       = '';
			$this->type         = '';
		}

	}
	// }}}

	// {{{ Image Class
	class _image extends _tag {
		function _image() {
			$this->filename = '';
			$this->align = 'left';
			$this->marginleft = "auto";
			$this->marginright = "auto";
			$this->effect = '';
			$this->width = '';
			$this->height = '';
		}
	}
	// }}}

	// {{{ Example Class
	class _example extends _tag {
		function _example() {
			$this->filename = '';
			$this->type = 'php';
			$this->fontsize = '2em';
			$this->rfontsize = '1.8em';
			$this->marginright = '3em';
			$this->marginleft = '3em';
			$this->margintop = '1em';
			$this->marginbottom = '0.8em';
			$this->hide = false;
			$this->result = false;
			$this->width = '';
			$this->condition = '';
			$this->linktext = "Result";
			$this->iwidth = '100%';
			$this->iheight = '80%';
			$this->localhost = false;
			$this->effect = '';
			$this->linenumbers = false;
		}

		function _highlight_none($fn) {
			$data = file_get_contents($fn);
			echo '<pre>' . htmlspecialchars($data) . "</pre>\n";
		}
	
		// {{{ highlight()	
		function highlight() {
			global $slideDir;
			static $temap = array(
				'py' => 'python',
				'pl' => 'perl',
				'php' => 'php',
				'html' => 'html',
				'sql' => 'sql',
				'java' => 'java',
				'xml' => 'xml',
				'c' => 'c'
			);

			if(!empty($this->filename)) {
				$_html_filename = preg_replace('/\?.*$/','',$slideDir.$this->filename);
				if ($this->type == 'php') {
					$p = pathinfo($this->filename);
					$this->type = @$temap[$p['extension']];
				}
				switch($this->type) {
					case 'php':
					case 'genimage':
					case 'iframe':
					case 'link':
					case 'nlink':
					case 'embed':
					case 'flash':
					case 'system':
						if ($this->linenumbers) {
							ob_start();
							highlight_file($_html_filename);
							$contents = ob_get_contents();
							ob_end_clean();
							echo add_line_numbers($contents);
						} else {
							highlight_file($_html_filename);
						}
						break;
					case 'c':
						$prog = trim(`which c2html`);
						if (!empty($prog)) {
							print `cat {$_html_filename} | $prog -cs`;
						} else {
							$this->_highlight_none($_html_filename);
						}
						break;
					case 'perl':
						$prog = trim(`which perl2html`);
						if (!empty($prog)) {
							print `cat {$_html_filename} | $prog -cs`;
						} else {
							$this->_highlight_none($_html_filename);
						}
						break;
					case 'java':
						$prog = trim(`which java2html`);
						if (!empty($prog)) {
							print `cat {$_html_filename} | java2html -cs`;
						} else {
							$this->_highlight_none($_html_filename);
						}
						break;
					case 'python':
						$prog = trim(`which code2html`);
						if (!empty($prog)) {
							print nl2br(trim(`$prog -lpython --no-header -ohtml $_html_filename | sed -e 's/\t/\&nbsp\;\&nbsp;\&nbsp\; /g'`));
						} else {
							$this->_highlight_none($_html_filename);
						}
						break;
					case 'sql':
						$prog = trim(`which code2html`);
						if (!empty($prog)) {
							print "<pre>";
							print `$prog --no-header -lsql $_html_filename`;
							print "</pre>";
						} else {
							$this->_highlight_none($_html_filename);
						}
						break;
					case 'html':
						$_html_file = file_get_contents($_html_filename);
						echo $_html_file."\n";
						break;
					
					case 'shell':
					case 'xml':
					default:
						$this->_highlight_none($_html_filename);
						break;
				}
			} else {
				switch($this->type) {
					case 'php':
						if ($this->linenumbers) {
							$text = add_line_numbers($this->text);
							highlight_string($text);
						} else {
							highlight_string($this->text);
						}
						break;
					case 'shell':
						echo '<pre>'.markup_text(htmlspecialchars($this->text))."</pre>\n";
						break;
					case 'html':
						echo $this->text."\n";
						break;
					case 'perl':
					    $text = str_replace('"', '\\"', $this->text);
						print `echo "{$text}" | perl2html -cs`;
						break;
					case 'c':
					    $text = str_replace('"', '\\"', $this->text);
						print `echo "{$text}" | c2html -cs`;
						break;

					default:
						echo "<pre>".htmlspecialchars($this->text)."</pre>\n";
						break;
				}
			}
		}
		// }}}

	}
	// }}}

	// {{{ Break Class
	class _break extends _tag {
		function _break() {
			$this->lines = 1;
		}

	}
	// }}}

	// {{{ List Class
	class _list extends _tag {
		function _list() {
			$this->fontsize    = '3em';
			$this->marginleft  = '0em';
			$this->marginright = '0em';
			$this->num = 1;
			$this->alpha = 'a';
		}

	}
	// }}}

	// {{{ Bullet Class
	class _bullet extends _tag {

		function _bullet() {
			$this->text = '';
			$this->effect = '';
			$this->id = '';
			$this->type = '';
		}

	}
	// }}}

	// {{{ Table Class
	class _table extends _tag {
		function _table() {
			$this->fontsize    = '3em';
			$this->marginleft  = '0em';
			$this->marginright = '0em';
			$this->border = 0;
			$this->columns = 2;
		}

	}
	// }}}

	// {{{ Cell Class
	class _cell extends _tag {

		function _cell() {
			$this->text = '';
			$this->slide = '';
			$this->id = '';
			$this->end_row = false;
			$this->offset = 0;
		}

	}
	// }}}

	// {{{ Link Class
	class _link extends _tag {

		function _link() {
			$this->href  = '';
			$this->align = 'left';
			$this->fontsize     = '2em';
			$this->textcolor    = '#000000';
			$this->marginleft   = '0em';
			$this->marginright  = '0em';
			$this->margintop    = '0em';	
			$this->marginbottom = '0em';	
		}

	}
	// }}}

	// {{{ PHP Eval Class
	class _php extends _tag {

		function _php() {
			$this->filename = '';
		}

	}
	// }}}

	// {{{ Divider Class
	class _divide extends _tag {
		/* empty */
	}
	// }}}

	// {{{ Footer Class
	class _footer extends _tag {
		/* empty */
	}
	// }}}
?>
