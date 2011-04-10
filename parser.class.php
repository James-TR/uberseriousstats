<?php

/**
 * Copyright (c) 2007-2011, Jos de Ruijter <jos@dutnie.nl>
 *
 * Permission to use, copy, modify, and/or distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 *
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */

/**
 * General parse instructions. This class will be extended by a class with logfile format specific parse instructions.
 */
abstract class parser extends base
{
	/**
	 * Default settings for this script, can be overridden in the config file.
	 * These should all appear in $settings_list[] along with their type.
	 */
	private $minstreak = 5;
	private $nick_maxlen = 255;
	private $nick_minlen = 1;
	private $quote_preflen = 25;
	private $wordtracking = true;

	/**
	 * Variables that shouldn't be tampered with.
	 */
	private $hex_latin1supplement = '[\x80-\xFF]';
	private $hex_validutf8 = '([\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})';
	private $newline = '';
	private $nicks_objs = array();
	private $settings_list = array(
		'minstreak' => 'int',
		'nick_maxlen' => 'int',
		'nick_minlen' => 'int',
		'outputbits' => 'int',
		'quote_preflen' => 'int',
		'wordtracking' => 'bool');
	private $smileys = array(
		'=]' => 's_01',
		'=)' => 's_02',
		';x' => 's_03',
		';p' => 's_04',
		';]' => 's_05',
		';-)' => 's_06',
		';)' => 's_07',
		';(' => 's_08',
		':x' => 's_09',
		':p' => 's_10',
		':d' => 's_11',
		':>' => 's_12',
		':]' => 's_13',
		':\\' => 's_14',
		':/' => 's_15',
		':-)' => 's_16',
		':)' => 's_17',
		':(' => 's_18',
		'\\o/' => 's_19');
	private $words_objs = array();
	protected $date = '';
	protected $l_00 = 0;
	protected $l_01 = 0;
	protected $l_02 = 0;
	protected $l_03 = 0;
	protected $l_04 = 0;
	protected $l_05 = 0;
	protected $l_06 = 0;
	protected $l_07 = 0;
	protected $l_08 = 0;
	protected $l_09 = 0;
	protected $l_10 = 0;
	protected $l_11 = 0;
	protected $l_12 = 0;
	protected $l_13 = 0;
	protected $l_14 = 0;
	protected $l_15 = 0;
	protected $l_16 = 0;
	protected $l_17 = 0;
	protected $l_18 = 0;
	protected $l_19 = 0;
	protected $l_20 = 0;
	protected $l_21 = 0;
	protected $l_22 = 0;
	protected $l_23 = 0;
	protected $l_night = 0;
	protected $l_morning = 0;
	protected $l_afternoon = 0;
	protected $l_evening = 0;
	protected $l_total = 0;
	protected $linenum = 0;
	protected $mysqli;
	protected $prevline = '';
	protected $prevnick = '';
	protected $streak = 0;

	final public function __construct($settings)
	{
		parent::__construct();

		foreach ($this->settings_list as $key => $type) {
			if (!array_key_exists($key, $settings)) {
				continue;
			}

			if ($type == 'string') {
				$this->$key = $settings[$key];
			} elseif ($type == 'int') {
				$this->$key = (int) $settings[$key];
			} elseif ($type == 'bool') {
				if (strtolower($settings[$key]) == 'true') {
					$this->$key = true;
				} elseif (strtolower($settings[$key]) == 'false') {
					$this->$key = false;
				}
			}
		}
	}

	/**
	 * Create an object of the nick if it doesn't already exist.
	 * Return the lowercase nick for further referencing by the calling function.
	 */
	final private function add_nick($csnick, $datetime)
	{
		$nick = strtolower($csnick);

		if (!array_key_exists($nick, $this->nicks_objs)) {
			$this->nicks_objs[$nick] = new nick($csnick);
			$this->nicks_objs[$nick]->set_value('date', $this->date);
		} else {
			$this->nicks_objs[$nick]->set_value('csnick', $csnick);
		}

		if (!is_null($datetime)) {
			$this->nicks_objs[$nick]->set_lastseen($datetime);
		}

		return $nick;
	}

	final private function add_word($csword, $length)
	{
		$word = strtolower($csword);

		if (!array_key_exists($word, $this->words_objs)) {
			$this->words_objs[$word] = new word($word);
			$this->words_objs[$word]->set_value('length', $length);
		}

		$this->words_objs[$word]->add_value('total', 1);
	}

	/**
	 * Parser function for gzipped logs.
	 */
	final public function gzparse_log($logfile, $firstline)
	{
		if (($zp = @gzopen($logfile, 'rb')) === false) {
			$this->output('critical', 'gzparse_log(): failed to open gzip file: \''.$logfile.'\'');
		}

		$this->output('notice', 'gzparse_log(): parsing logfile: \''.$logfile.'\' from line '.$firstline);

		while (!gzeof($zp)) {
			$line = gzgets($zp);
			$this->linenum++;

			if ($this->linenum < $firstline) {
				continue;
			}

			$line = $this->normalize_line($line);

			/**
			 * Pass on the normalized line to the logfile format specific parser class extending this class.
			 */
			$this->parse_line($line);
			$this->prevline = $line;
		}

		/**
		 * If the last line parsed contains data we increase $linenum by one so the line won't get parsed a second time on next run.
		 * However, if the last line is empty we leave the pointer at $linenum since logging might continue on this line (depending on how lines are terminated).
		 */
		if (!empty($line)) {
			$this->linenum++;
		}

		gzclose($zp);
		$this->output('notice', 'gzparse_log(): parsing completed');
	}

	/**
	 * Checks if a line is valid UTF-8 and convert all non valid bytes into valid multibyte UTF-8.
	 */
	final private function normalize_line($line)
	{
		if (!preg_match('/^'.$this->hex_validutf8.'+$/', $line)) {
			$this->newline = '';

			while ($line != '') {
				/**
				 * Match the first valid multibyte character or otherwise a single byte;
				 * Pass it on to rebuild_line() and replace the character with an empty string (making $line shorter);
				 * Continue until $line is zero bytes in length.
				 */
				$line = preg_replace('/^'.$this->hex_validutf8.'|./es', '$this->rebuild_line(\'$0\')', $line);
			}

			/*
			 * Set $line to the rebuilt $newline.
			 */
			$line = $this->newline;
		}

		/**
		 * 1. Remove control codes from the Basic Latin (7-bit ASCII) and Latin-1 Supplement character sets (the latter after conversion to multibyte).
		 *    0x03 is used for (mIRC) color codes and may be followed by additional characters; remove those as well.
		 * 2. Replace all possible formations of adjacent spaces and tabs, including the no-break space (multibyte), with a single space.
		 * 3. Remove whitespace characters at the beginning and end of a line.
		 */
		$line = preg_replace(array('/[\x00-\x02\x04-\x08\x0A-\x1F\x7F]|\x03([0-9]{1,2}(,[0-9]{1,2})?)?|\xC2[\x80-\x9F]/', '/([\x09\x20]|\xC2\xA0)+/', '/^\x20|\x20$/'), array('', ' ', ''), $line);
		return $line;
	}

	/**
	 * Parser function for normal logs.
	 */
	final public function parse_log($logfile, $firstline)
	{
		if (($fp = @fopen($logfile, 'rb')) === false) {
			$this->output('critical', 'parse_log(): failed to open file: \''.$logfile.'\'');
		}

		$this->output('notice', 'parse_log(): parsing logfile: \''.$logfile.'\' from line '.$firstline);

		while (!feof($fp)) {
			$line = fgets($fp);
			$this->linenum++;

			if ($this->linenum < $firstline) {
				continue;
			}

			$line = $this->normalize_line($line);

			/**
			 * Pass on the normalized line to the logfile format specific parser class extending this class.
			 */
			$this->parse_line($line);
			$this->prevline = $line;
		}

		/**
		 * If the last line parsed contains data we increase $linenum by one so the line won't get parsed a second time on next run.
		 * However, if the last line is empty we leave the pointer at $linenum since logging might continue on this line (depending on how lines are terminated).
		 */
		if (!empty($line)) {
			$this->linenum++;
		}

		fclose($fp);
		$this->output('notice', 'parse_log(): parsing completed');
	}

	/**
	 * Build a new line consisting of valid UTF-8 from the characters passed along in $char.
	 */
	final private function rebuild_line($char) {
		/**
		 * 1. Valid UTF-8 is passed along unmodified.
		 * 2. Single byte characters from the Latin-1 Supplement are converted to multibyte unicode.
		 * 3. Everything else is converted to the unicode questionmark sign (commonly used to depict unknown characters).
		 */
		if (preg_match('/^'.$this->hex_validutf8.'$/', $char)) {
			$this->newline .= $char;
		} elseif (preg_match('/^'.$this->hex_latin1supplement.'$/', $char)) {
			$char = preg_replace('/^'.$this->hex_latin1supplement.'$/e', 'pack(\'C*\', (ord(\'$0\') >> 6) | 0xC0, (ord(\'$0\') & 0x3F) | 0x80)', $char);
			$this->newline .= $char;
		} else {
			$this->newline .= "\xEF\xBF\xBD";
		}

		/**
		 * Returns nothing; see normalize_line() for it to make sense.
		 */
		return '';
	}

	final protected function set_action($datetime, $csnick, $line)
	{
		if (!$this->validate_nick($csnick)) {
			$this->output('warning', 'set_action(): invalid nick: \''.$csnick.'\' on line '.$this->linenum);
		} else {
			$nick = $this->add_nick($csnick, $datetime);
			$this->nicks_objs[$nick]->add_value('actions', 1);

			if (strlen($line) <= 255) {
				if (strlen($line) >= $this->quote_preflen) {
					$this->nicks_objs[$nick]->add_quote('ex_actions', 'long', $line);
				} else {
					$this->nicks_objs[$nick]->add_quote('ex_actions', 'short', $line);
				}
			}
		}
	}

	final protected function set_join($datetime, $csnick)
	{
		if (!$this->validate_nick($csnick)) {
			$this->output('warning', 'set_join(): invalid nick: \''.$csnick.'\' on line '.$this->linenum);
		} else {
			$nick = $this->add_nick($csnick, $datetime);
			$this->nicks_objs[$nick]->add_value('joins', 1);
		}
	}

	final protected function set_kick($datetime, $csnick_performing, $csnick_undergoing, $line)
	{
		if (!$this->validate_nick($csnick_performing)) {
			$this->output('warning', 'set_kick(): invalid "performing" nick: \''.$csnick_performing.'\' on line '.$this->linenum);
		} elseif (!$this->validate_nick($csnick_undergoing)) {
			$this->output('warning', 'set_kick(): invalid "undergoing" nick: \''.$csnick_undergoing.'\' on line '.$this->linenum);
		} else {
			$nick_performing = $this->add_nick($csnick_performing, $datetime);
			$nick_undergoing = $this->add_nick($csnick_undergoing, $datetime);
			$this->nicks_objs[$nick_performing]->add_value('kicks', 1);
			$this->nicks_objs[$nick_undergoing]->add_value('kicked', 1);

			if (strlen($line) <= 255) {
				$this->nicks_objs[$nick_performing]->set_value('ex_kicks', $line);
				$this->nicks_objs[$nick_undergoing]->set_value('ex_kicked', $line);
			}
		}
	}

	final protected function set_mode($datetime, $csnick_performing, $csnick_undergoing, $mode)
	{
		if (!$this->validate_nick($csnick_performing)) {
			$this->output('warning', 'set_mode(): invalid "performing" nick: \''.$csnick_performing.'\' on line '.$this->linenum);
		} elseif (!$this->validate_nick($csnick_undergoing)) {
			$this->output('warning', 'set_mode(): invalid "undergoing" nick: \''.$csnick_undergoing.'\' on line '.$this->linenum);
		} else {
			$nick_performing = $this->add_nick($csnick_performing, $datetime);
			$nick_undergoing = $this->add_nick($csnick_undergoing, $datetime);

			switch ($mode) {
				case '+o':
					$this->nicks_objs[$nick_performing]->add_value('m_op', 1);
					$this->nicks_objs[$nick_undergoing]->add_value('m_opped', 1);
					break;
				case '+v':
					$this->nicks_objs[$nick_performing]->add_value('m_voice', 1);
					$this->nicks_objs[$nick_undergoing]->add_value('m_voiced', 1);
					break;
				case '-o':
					$this->nicks_objs[$nick_performing]->add_value('m_deop', 1);
					$this->nicks_objs[$nick_undergoing]->add_value('m_deopped', 1);
					break;
				case '-v':
					$this->nicks_objs[$nick_performing]->add_value('m_devoice', 1);
					$this->nicks_objs[$nick_undergoing]->add_value('m_devoiced', 1);
					break;
			}
		}
	}

	final protected function set_nickchange($datetime, $csnick_performing, $csnick_undergoing)
	{
		if (!$this->validate_nick($csnick_performing)) {
			$this->output('warning', 'set_nickchange(): invalid "performing" nick: \''.$csnick_performing.'\' on line '.$this->linenum);
		} elseif (!$this->validate_nick($csnick_undergoing)) {
			$this->output('warning', 'set_nickchange(): invalid "undergoing" nick: \''.$csnick_undergoing.'\' on line '.$this->linenum);
		} else {
			$nick_performing = $this->add_nick($csnick_performing, $datetime);
			$nick_undergoing = $this->add_nick($csnick_undergoing, $datetime);
			$this->nicks_objs[$nick_performing]->add_value('nickchanges', 1);
		}
	}

	final protected function set_normal($datetime, $csnick, $line)
	{
		if (!$this->validate_nick($csnick)) {
			$this->output('warning', 'set_normal(): invalid nick: \''.$csnick.'\' on line '.$this->linenum);
		} else {
			$nick = $this->add_nick($csnick, $datetime);
			$this->nicks_objs[$nick]->set_lasttalked($datetime);
			$this->nicks_objs[$nick]->set_value('activedays', 1);
			$this->nicks_objs[$nick]->add_value('characters', strlen($line));

			/**
			 * Keeping track of monologues.
			 */
			if ($nick == $this->prevnick) {
				$this->streak++;
			} else {
				/**
				 * Ohno! Someone else type a line and the previous streak is interrupted. Check if the streak qualifies as a monologue and store it.
				 */
				if ($this->streak >= $this->minstreak) {
					/**
					 * If the current line count is 0 then $prevnick is not known to us yet (only seen in previous parse run).
					 * It's safe to assume that $prevnick is a valid nick since it was set by set_normal().
					 * We will create an object for it here so we can add the monologue data. Don't worry about $prevnick being lowercase,
					 * we won't update "user_details" if $prevnick isn't seen plus $csnick will get a refresh on any other activity.
					 */
					if ($this->l_total == 0) {
						$this->add_nick($this->prevnick, null);
					}

					$this->nicks_objs[$this->prevnick]->add_value('monologues', 1);

					if ($this->streak > $this->nicks_objs[$this->prevnick]->get_value('topmonologue')) {
						$this->nicks_objs[$this->prevnick]->set_value('topmonologue', $this->streak);
					}
				}

				$this->streak = 1;
				$this->prevnick = $nick;
			}

			$day = strtolower(date('D', strtotime($this->date)));
			$hour = substr($datetime, 11, 2);

			if (preg_match('/^0[0-5]$/', $hour)) {
				$this->l_night++;
				$this->nicks_objs[$nick]->add_value('l_night', 1);
				$this->nicks_objs[$nick]->add_value('l_'.$day.'_night', 1);
			} elseif (preg_match('/^(0[6-9]|1[01])$/', $hour)) {
				$this->l_morning++;
				$this->nicks_objs[$nick]->add_value('l_morning', 1);
				$this->nicks_objs[$nick]->add_value('l_'.$day.'_morning', 1);
			} elseif (preg_match('/^1[2-7]$/', $hour)) {
				$this->l_afternoon++;
				$this->nicks_objs[$nick]->add_value('l_afternoon', 1);
				$this->nicks_objs[$nick]->add_value('l_'.$day.'_afternoon', 1);
			} elseif (preg_match('/^(1[89]|2[0-3])$/', $hour)) {
				$this->l_evening++;
				$this->nicks_objs[$nick]->add_value('l_evening', 1);
				$this->nicks_objs[$nick]->add_value('l_'.$day.'_evening', 1);
			}

			$this->{'l_'.$hour}++;
			$this->l_total++;

			/**
			 * The "words" count below has no relation with the words which are stored in the database.
			 * It simply counts all character groups separated by whitespace.
			 */
			$words = explode(' ', $line);
			$this->nicks_objs[$nick]->add_value('words', count($words));
			$skipquote = false;

			foreach ($words as $csword) {
				/**
				 * Behold the amazing smileys regexp.
				 */
				if (preg_match('/^(=[])]|;([]()xp]|-\))|:([]\/()\\\>xpd]|-\))|\\\o\/)$/i', $csword)) {
					$this->nicks_objs[$nick]->add_value($this->smileys[strtolower($csword)], 1);

				/**
				 * Only catch URLs which were intended to be clicked on; most clients can handle URLs that begin with "www." or "http://" and such.
				 * If we would apply a more liberal approach we are likely to run into filenames (e.g. .py .com), libraries (e.g. .so) and other words that validate as a URL.
				 */
				} elseif (preg_match('/^(www\.|https?:\/\/)/i', $csword)) {
					/**
					 * Regardless of it being a valid URL or not we set $skipquote to true. This variable enables us to exclude quotes that have
					 * a URL (or something that looks like it) in them. This is to safeguard a tidy presentation on the statspage.
					 */
					$skipquote = true;

					if (($urldata = $this->urltools->get_elements($csword)) !== false) {
						if (strlen($urldata['url']) > 1024) {
							$this->output('debug', 'set_normal(): skipping url on line '.$this->linenum.': exceeds column length (1024)');
						} else {
							$this->nicks_objs[$nick]->add_url($urldata, $datetime);
							$this->nicks_objs[$nick]->add_value('urls', 1);
						}
					} else {
						$this->output('debug', 'set_normal(): invalid url: \''.$csword.'\' on line '.$this->linenum);
					}

				/**
				 * To keep it simple we only track words composed of the characters A through Z and letters defined in the Latin-1 Supplement.
				 */
				} elseif ($this->wordtracking && preg_match('/^([a-z]|\xC3([\x80-\x96]|[\x98-\xB6]|[\xB8-\xBF]))+$/i', $csword)) {
					/**
					 * Calculate the real length of the word without additional multibyte string functions.
					 */
					$length = strlen(preg_replace('/\xC3([\x80-\x96]|[\x98-\xB6]|[\xB8-\xBF])/', '.', $csword));

					/**
					 * Words consisting of 30+ characters are most likely not real words so we skip those.
					 */
					if ($length <= 30) {
						$this->add_word($csword, $length);
					}
				}
			}

			$this->nicks_objs[$nick]->add_value('l_'.$hour, 1);
			$this->nicks_objs[$nick]->add_value('l_total', 1);

			if (!$skipquote && strlen($line) <= 255) {
				if (strlen($line) >= $this->quote_preflen) {
					$this->nicks_objs[$nick]->add_quote('quote', 'long', $line);
				} else {
					$this->nicks_objs[$nick]->add_quote('quote', 'short', $line);
				}
			}

			if (!$skipquote && strlen($line) >= 2 && strtoupper($line) == $line && strlen(preg_replace('/[A-Z]/', '', $line)) * 2 < strlen($line)) {
				$this->nicks_objs[$nick]->add_value('uppercased', 1);

				if (!$skipquote && strlen($line) <= 255) {
					if (strlen($line) >= $this->quote_preflen) {
						$this->nicks_objs[$nick]->add_quote('ex_uppercased', 'long', $line);
					} else {
						$this->nicks_objs[$nick]->add_quote('ex_uppercased', 'short', $line);
					}
				}
			}

			if (!$skipquote && preg_match('/!$/', $line)) {
				$this->nicks_objs[$nick]->add_value('exclamations', 1);

				if (!$skipquote && strlen($line) <= 255) {
					if (strlen($line) >= $this->quote_preflen) {
						$this->nicks_objs[$nick]->add_quote('ex_exclamations', 'long', $line);
					} else {
						$this->nicks_objs[$nick]->add_quote('ex_exclamations', 'short', $line);
					}
				}
			} elseif (!$skipquote && preg_match('/\?$/', $line)) {
				$this->nicks_objs[$nick]->add_value('questions', 1);

				if (!$skipquote && strlen($line) <= 255) {
					if (strlen($line) >= $this->quote_preflen) {
						$this->nicks_objs[$nick]->add_quote('ex_questions', 'long', $line);
					} else {
						$this->nicks_objs[$nick]->add_quote('ex_questions', 'short', $line);
					}
				}
			}
		}
	}

	final protected function set_part($datetime, $csnick)
	{
		if (!$this->validate_nick($csnick)) {
			$this->output('warning', 'set_part(): invalid nick: \''.$csnick.'\' on line '.$this->linenum);
		} else {
			$nick = $this->add_nick($csnick, $datetime);
			$this->nicks_objs[$nick]->add_value('parts', 1);
		}
	}

	final protected function set_quit($datetime, $csnick)
	{
		if (!$this->validate_nick($csnick)) {
			$this->output('warning', 'set_quit(): invalid nick: \''.$csnick.'\' on line '.$this->linenum);
		} else {
			$nick = $this->add_nick($csnick, $datetime);
			$this->nicks_objs[$nick]->add_value('quits', 1);
		}
	}

	final protected function set_slap($datetime, $csnick_performing, $csnick_undergoing)
	{
		if (!$this->validate_nick($csnick_performing)) {
			$this->output('warning', 'set_slap(): invalid "performing" nick: \''.$csnick_performing.'\' on line '.$this->linenum);
		} else {
			$nick_performing = $this->add_nick($csnick_performing, $datetime);
			$this->nicks_objs[$nick_performing]->add_value('slaps', 1);

			if (!is_null($csnick_undergoing)) {
				/**
				 * Clean possible network prefix (psyBNC) from undergoing nick.
				 */
				if (substr_count($csnick_undergoing, '~') + substr_count($csnick_undergoing, '\'') == 1) {
					$this->output('debug', 'set_slap(): cleaning "undergoing" nick: \''.$csnick_undergoing.'\' on line '.$this->linenum);
					$tmp = preg_split('/[~\']/', $csnick_undergoing, 2);
					$csnick_undergoing = $tmp[1];
				}

				if (!$this->validate_nick($csnick_undergoing)) {
					$this->output('warning', 'set_slap(): invalid "undergoing" nick: \''.$csnick_undergoing.'\' on line '.$this->linenum);
				} else {
					/**
					 * Don't pass a time when adding the undergoing nick while it may only be referred to instead of being seen for real.
					 */
					$nick_undergoing = $this->add_nick($csnick_undergoing, null);
					$this->nicks_objs[$nick_undergoing]->add_value('slapped', 1);
				}
			}
		}
	}

	final protected function set_topic($datetime, $csnick, $line)
	{
		if (!$this->validate_nick($csnick)) {
			$this->output('warning', 'set_topic(): invalid nick: \''.$csnick.'\' on line '.$this->linenum);
		} else {
			$nick = $this->add_nick($csnick, $datetime);
			$this->nicks_objs[$nick]->add_value('topics', 1);

			/**
			 * Keep track of every single topic set.
			 */
			if (strlen($line) > 1024) {
				$this->output('debug', 'set_topic(): skipping topic on line '.$this->linenum.': exceeds column length (1024)');
			} else {
				$this->nicks_objs[$nick]->add_topic($line, $datetime);
			}
		}
	}

	/**
	 * Check on syntax and defined lengths. Maximum length should not exceed 255 which is the maximum database field length.
	 */
	final private function validate_nick($csnick)
	{
		if ($csnick != '0' && preg_match('/^[][^{}|\\\`_0-9a-z-]{'.($this->nick_minlen > $this->nick_maxlen ? 1 : $this->nick_minlen).','.($this->nick_maxlen > 255 ? 255 : $this->nick_maxlen).'}$/i', $csnick)) {
			return true;
		} else {
			return false;
		}
	}

	final public function write_data($mysqli)
	{
		$this->mysqli = $mysqli;

		/**
		 * Write channel totals to the database.
		 */
		if ($this->l_total != 0) {
			$query = @mysqli_query($this->mysqli, 'select * from `channel` where `date` = \''.mysqli_real_escape_string($this->mysqli, $this->date).'\'') or $this->output('critical', 'mysqli: '.mysqli_error($this->mysqli));
			$rows = mysqli_num_rows($query);

			if (empty($rows)) {
				$insertquery = $this->create_insert_query(array('l_00', 'l_01', 'l_02', 'l_03', 'l_04', 'l_05', 'l_06', 'l_07', 'l_08', 'l_09', 'l_10', 'l_11', 'l_12', 'l_13', 'l_14', 'l_15', 'l_16', 'l_17', 'l_18', 'l_19', 'l_20', 'l_21', 'l_22', 'l_23', 'l_night', 'l_morning', 'l_afternoon', 'l_evening', 'l_total'));
				@mysqli_query($this->mysqli, 'insert into `channel` set `date` = \''.mysqli_real_escape_string($this->mysqli, $this->date).'\','.$insertquery) or $this->output('critical', 'mysqli: '.mysqli_error($this->mysqli));
			} else {
				$result = mysqli_fetch_object($query);
				$updatequery = $this->create_update_query($result, array('date'));

				if (!is_null($updatequery)) {
					@mysqli_query($this->mysqli, 'update `channel` set'.$updatequery.' where `date` = \''.mysqli_real_escape_string($this->mysqli, $this->date).'\'') or $this->output('critical', 'mysqli: '.mysqli_error($this->mysqli));
				}
			}
		}

		/**
		 * Write user data to the database.
		 */
		foreach ($this->nicks_objs as $nick) {
			$nick->write_data($this->mysqli);
		}

		/**
		 * Write word data to the database.
		 */
		foreach ($this->words_objs as $word) {
			$word->write_data($this->mysqli);
		}

		/**
		 * Write streak data (history) to the database.
		 */
		if ($this->l_total != 0) {
			@mysqli_query($this->mysqli, 'truncate table `streak_history`') or $this->output('critical', 'mysqli: '.mysqli_error($this->mysqli));
			@mysqli_query($this->mysqli, 'insert into `streak_history` set `prevnick` = \''.mysqli_real_escape_string($this->mysqli, $this->prevnick).'\', `streak` = '.$this->streak) or $this->output('critical', 'mysqli: '.mysqli_error($this->mysqli));
		}
	}
}

?>
