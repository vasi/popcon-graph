<?php

error_reporting(E_ALL | E_STRICT);
date_default_timezone_set('America/Montreal');

class PackageGraph {	
	static function run() {
		$pg = new PackageGraph();
		return $pg->url();
	}
	
	private static $alnum_chars = NULL;
	static function alnum_chars() {
		if (is_null(self::$alnum_chars)) {
			# Assume ASCII
			$groups = array('A' => 'Z', 'a' => 'z', '0' => '9', '-' => '-', '.' => '.');
			foreach ($groups as $start => $end) {
				for ($i = ord($start); $i <= ord($end); ++$i)
					self::$alnum_chars[] = chr($i);
			}
		}
		return self::$alnum_chars;
	}
	
	function url() {
		$path = 'http://chart.apis.google.com/chart?';
		$params['chs'] = sprintf("%dx%d", $this->width, $this->height);	# size
		$params['cht'] = 'lc';											# line chart
		$params['chd'] = $this->data_points();
		
		$params['chco'] = $this->colors(count($this->packages));		# colors
		$params['chdl'] = implode('|', $this->packages);				# legend
		$params['chg'] = $this->gridlines();
		$this->axes($params);
		
		//var_dump($params); die;
		return $path . http_build_query($params);
	}
	
	function __construct() {
		# Max 300k pixels
		$this->height = 500;
		$this->width = 600;
		
		$this->precision = 2;	# 1 or 2 are valid
		$this->granularity = $this->width / 20; # pixels per data point
	}
	
	function gridlines() {
		$offset = $this->scale(ceil($this->min), 100);
		$step = 1 / ($this->max - $this->min) * 100 / 2;
		
		# xstep, ystep, line, blank, xoff, yoff
		$parms = array(0, sprintf("%.2f", $step), 2, 6, 0, sprintf("%.2f", $offset));
		return implode(',', $parms);
	}
	
	function axes(&$params) {
		$axes[] = $this->yaxis();
		
		# Prefix the axis params
		$axparms = array();
		for ($i = 0; $i < count($axes); ++$i) {
			foreach ($axes[$i] as $k => $v) {
				$axparms[$k][] = ($k == 'chxt') ? $v
								: (($k == 'chxl') ? "$i:|$v"
								: "$i,$v");
			}
		}
		
		# Combine the axis params
		foreach ($axparms as $k => $v) {
			$params[$k] = implode($k == 'chxt' ? ',' : '|', $v);
		}
	}
	
	function yaxis() {
		$ax = array();
		$ax['chxt'] = 'y'; # Y-Axis
		
		for ($l = floor($this->min); $l < $this->max; ++$l) {
			$y10 = pow(10, $l);
			foreach (array(1, 2, 3, 5) as $mult) {
				$ypow  = $y10 * $mult;
				$y = log10($ypow);
				if ($y < $this->min || $y > $this->max)
					continue;
				
				$ax['chxl'][] = $ypow;					# label
				$ax['chxp'][] = round($this->scale($y, 100));	# position
			}
		}
		
		foreach ($ax as $k => &$v) {
			$v = ($k == 'chxt') ? $v
				: implode($k == 'chxl' ? '|' : ',', $v);
		}
		return $ax;
	}
	
	function colors($n) {
		for ($i = 0; $i < $n; ++$i) {
			# Can't we do better than this?
			$h = (360.0 / 13) * 4 * $i;
			$v = 0.7 + 0.3 * ($i % 2);
			
			$colors[] = $this->hsv($h, 1.0, $v);
		}
		return implode(',', $colors);
	}
	
	function hsv($h, $s, $v) {
		// Thanks, wikipedia!
		
		$h %= 360;
		$zone = floor($h / 60);
		$frac = $h / 60 - $zone;
		
		$p = $v * (1 - $s);
		$q = $v * (1 - $frac * $s);
		$t = $v * (1 - (1 - $frac) * $s);
		
		switch ($zone) {
			case 0: list($r, $g, $b) = array($v, $t, $p); break;
			case 1: list($r, $g, $b) = array($q, $v, $p); break;
			case 2: list($r, $g, $b) = array($p, $v, $t); break;
			case 3: list($r, $g, $b) = array($p, $q, $v); break;
			case 4: list($r, $g, $b) = array($t, $p, $v); break;
			case 5: list($r, $g, $b) = array($v, $p, $q); break;
		}
		return sprintf("%02x%02x%02x", $r * 255, $g * 255, $b * 255);
	}
	
	function data_points() {
		$this->data = $this->load_data();
		$this->normalize_data();
		$this->analyze_data();
		return $this->encode_data();
	}
	
	function normalize_data() {
		foreach ($this->data as $date => &$packages) {
			foreach ($packages as $pkg => &$val) {
				$val = $val == 0 ? NULL : log10($val);
			}
		}
	}
	
	function analyze_data() {
		# Find:
		# 	$this->packages: All packages, ordered by last vote
		#	$this->max, $this->min: Maximum, minimum values
		
		foreach ($this->data as $date => $packages) {
			foreach ($packages as $package => $val) {
				$last[$package] = $val;
				
				if (!isset($this->min) || $val < $this->min)
					$this->min = $val;
				if (!isset($this->max) || $val > $this->max)
					$this->max = $val;
			}
		}
		
		// Really low y-values are silly
		$this->min = max($this->min, log10(15));
		
		arsort($last);
		$this->packages = array_keys($last);
	}
	
	function encode_data() {
		foreach ($this->packages as $pkg)
			$series[$pkg] = array();
		foreach ($this->data as $date => $packages) {
			foreach ($this->packages as $pkg) {
				$val = isset($packages[$pkg]) ? $packages[$pkg] : NULL;
				array_push($series[$pkg], $val);
			}
		}
		return $this->alnum_encode($series);
	}
	
	function alnum_encode($series) {
		foreach ($series as $ser) {
			$s = '';
			foreach ($ser as $val)
				$s .= $this->alnum_encode_value($val);
			$sections[] = $s;
		}
		return ($this->precision == 1 ? 's' : 'e') . ':' . implode(',', $sections);
	}
	
	function alnum_base() {
		return $this->precision == 1 ? 62 : count(self::alnum_chars());
	}
	
	function range() {
		return $this->alnum_range();
	}
	
	function alnum_range() {
		return pow($this->alnum_base(), $this->precision) - 1;
	}
	
	function scale($val, $range = NULL) {
		$val = max($val, $this->min);
		return ((float)$val - $this->min) / ($this->max - $this->min)
			* ($range ? $range : $this->range());
	}
	
	function alnum_encode_value($val) {
		if (is_null($val))
			return str_repeat('_', $this->precision);
		
		$chars = self::alnum_chars();
		$n = $this->alnum_base();
		$scaled = round($this->scale($val));
		
		$rev = '';
		for ($i = 0; $i < $this->precision; ++$i) {
			$rev .= $chars[$scaled % $n];
			$scaled = floor($scaled / $n);
		}
		return strrev($rev);
	}
	
	function load_data() {
		$dir = dirname(__FILE__) . "/stats";
		$files = preg_grep('/^\d{4}-\d{2}-\d{2}$/', scandir($dir));
		sort($files);
		
		# only load as many data points as are needed
		$scan = (float)count($files) / $this->width * $this->granularity;
		if ($scan < 1)
			$scan = 1;
		
		for ($i = 0.0; round($i) < count($files); $i += $scan) {
			$file = $files[round($i)];
			$date = strtotime($file);
			foreach (file("$dir/$file") as $line) {
				preg_match('/^(\S+),(\d+)/', $line, $match);
				list($dummy, $package, $vote) = $match;
				$data[$date][$package] = $vote;
			}
		}
		return $data;
	}
}

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN"
	"http://www.w3.org/TR/html4/strict.dtd">
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<title>Popcon</title>
</head>
<body>
	<div>
	<img src='<?php echo htmlentities(PackageGraph::run()); ?>' alt='Popcon graph'>
	</div>
</body>
</html>
