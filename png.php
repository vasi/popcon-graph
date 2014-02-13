<?php

class Rect {
	public $x, $y, $w, $h;
	
	function __construct($x, $y, $w = 0, $h = 0) {
		$this->x = $x;
		$this->y = $y;
		$this->w = $w;
		$this->h = $h;
	}
	
	static function size($w, $h) {
		return new Rect(0, 0, $w, $h);
	}
	
	static function bounds($xmin, $ymin, $xmax, $ymax) {
		return new Rect($xmin, $ymin, $xmax - $xmin, $ymax - $ymin);
	}
	
	function xmax() { return $this->x + $this->w; }
	function ymax() { return $this->y + $this->h; }
}

class LegendGroup {
	public $pad, $text_height, $items, $y;
	
	public function __construct($pad, $text_height, $idx, $name, $y) {
		$this->pad = $pad;
		$this->text_height = $text_height;
		$this->items = array(array('idx' => $idx, 'name' => $name));
		$this->y = $y;
	}
	
	public function count() { return count($this->items); }
	public function height() { return $this->count() * $this->text_height
		+ ($this->count() - 1) * $this->pad; }
	public function min() { return $this->y - $this->height() / 2; }
	public function max() { return $this->y + $this->height() / 2; }
	
	public function merge($other) {
		$this->y = ($this->y * $this->count() + $other->y * $other->count())
			/ ($this->count() + $other->count());
		$this->items = array_merge($this->items, $other->items);
	}
	
	public function overlaps($other) {
		return $this->min() - $other->max() < $this->pad
			&& $other->min() - $this->max() < $this->pad;
	}
}

class PackageGraph {
	public $image, $imagesz;
	public $white, $black;
	
	public $series, $pct;
	public $data_bounds, $graph_bounds;
	public $font;
	
	private $log_range, $log_min;
	private $order, $zero_pix, $last, $label_pad;
	private $text_height, $text_mid;
	
	function __construct($size) {
		list($x, $y) = array(1000, 700);
		if (preg_match('/^(\d+)x(\d+)$/', $size, $match)) {
			list($dummy, $x, $y) = $match;
		}
		$this->create_image(Rect::size($x, $y));

		$this->graph_bounds = Rect::bounds(70, 20, $this->imagesz->w - 175,
				$this->imagesz->h - 30);
		
		$this->font = dirname(__FILE__) . "/DejaVuSans.ttf";
		$this->font_size = 9;
		$this->label_pad = 5;
		$this->zero_pix = 6; // Pixels below log(1) to place zero (-infinity sucks)
		
		$this->pct = true;
		$this->trunc_min = 15; // truncate really low values
		
		$this->dir = dirname(__FILE__) . "/stats";
		$this->load_data();
		$this->log_min = log10($this->data_bounds->y);
		$this->log_range = log10($this->data_bounds->ymax()) - $this->log_min;
		
		$this->draw_axes();
		foreach ($this->order as $idx => $name) {
			$this->draw_series($this->series[$name], $this->palette($idx));
		}
	}
	
	function create_image($sz) {
		$this->imagesz = $sz;
		$this->image = imageCreateTrueColor($sz->w, $sz->h);
		//imageAntiAlias($this->image, true);

		$this->white = imageColorAllocate($this->image, 255, 255, 255);
		$this->black = imageColorAllocate($this->image, 0, 0, 0);
		imageFillToBorder($this->image, 0, 0, $this->white, $this->white);
	}
	
	function display() {
		header("Content-type: image/png");
		imagePNG($this->image);
		imageDestroy($this->image);
	}
	
	function read_file($file) {
		$packages = array();
		foreach (file("$this->dir/$file") as $line) {
			preg_match('/^(\S+),(\d+)/', $line, $match);
			list($dummy, $package, $vote) = $match;
			$packages[$package] = $vote;
		}
		return $packages;
	}
	
	function load_data() {
		$this->series = array();
		$xmin = $xmax = $ymin = $ymax = NULL;
		$this->last = array(); // for ordering
		
		$files = scandir($this->dir);
		$current = $this->read_file(end($files));
		
		foreach ($files as $file) {
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $file))
				continue;		
			$date = strtotime($file);
			
			if (is_null($xmin) || $date < $xmin)
				$xmin = $date;
			if (is_null($xmax) || $date > $xmax)
				$xmax = $date;
			
			$packages = $this->read_file($file);
			$packages = array_intersect_key($packages, $current);
			
			# Calculate totals. HACK: Don't double-up firefoxes
			$total = 0;
			$firefox_versions = 0;
			foreach ($packages as $package => $vote) {
				if (preg_match('/^firefox-/', $package)) {
					$firefox_versions += $vote;
				} else {
					$total += $vote;
				}
			}
			if (isset($packages['firefox']) && $firefox_versions > $packages['firefox']) {
				$total += $firefox_versions - $packages['firefox'];
			}
			
			foreach ($packages as $package => $vote) {
				$val = $this->pct ? 100.0 * $vote / $total : $vote;
				$this->series[$package][$date] = $val;
				$this->last[$package] = $val;
				
				if (is_null($ymax) || $val > $ymax)
					$ymax = $val;
				if ($vote >= $this->trunc_min && (is_null($ymin) || $val < $ymin))
					$ymin = $val;
			}
		}
		
		arsort($this->last);
		$this->order = array_keys($this->last);
		
		$this->data_bounds = Rect::bounds($xmin, $ymin, $xmax, $ymax);
	}
	
	function draw_series($series, $color) {
		foreach ($series as $x => $y) {
			$scale_x = (int)($this->graph_bounds->w *
				(((float)$x - $this->data_bounds->x) / $this->data_bounds->w))
				+ $this->graph_bounds->x;
			if (!is_null($cur_x) && $cur_x == $scale_x) { // Duplicate point, merge
				$cur_tot += $y;
				++$cur_count;
			} else { // New point
				if (!is_null($cur_x)) { // Not the first point
					// Calculate average for this point
					$cur_y = (float)$cur_tot / $cur_count;
					if (!is_null($last_x)) {
						$this->graph_line($last_x, $last_y, $cur_x, $cur_y, $color);
					}
					$last_x = $cur_x;
					$last_y = $cur_y;
				}
				
				$cur_x = $scale_x;
				$cur_tot = $y;
				$cur_count = 1;
			}
		}
		if (!is_null($cur_x)) {
			$cur_y = (float)$cur_tot / $cur_count;
			if (!is_null($last_x)) { // Draw last line
				$this->graph_line($last_x, $last_y, $cur_x, $cur_y, $color);
			} else { // Just one point!
				imageFilledEllipse($this->image, $cur_x, $this->ycoord($cur_y),
					3, 3, $color);
			}
		}
	}
	
	function graph_line($x1, $y1, $x2, $y2, $color) {
		// x is pre-scaled, y is not
		$yc1 = $this->ycoord($y1);
		$yc2 = $this->ycoord($y2);
		imageLine($this->image, $x1, $yc1, $x2, $yc2, $color);
	}
	
	function ycoord($y) {
		if ($y == 0 || $y < $this->data_bounds->y) {
			$ly = 0;
		} else {
			$ly = (log10($y) - $this->log_min) / $this->log_range
				* ($this->graph_bounds->h - $this->zero_pix) + $this->zero_pix;
		}
		return $this->graph_bounds->ymax() - $ly; // zero-on-top coords
	}
	
	function palette($idx) {
		$h = (360.0 / 13) * 4 * $idx;
		$v = 0.7 + 0.3 * ($idx % 2);
		
		return $this->hsv($h, 1.0, $v);
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
		return imageColorAllocate($this->image, 255 * $r, 255 * $g, 255 * $b);
	}
	
	function draw_axes() {
		$gb = $this->graph_bounds;
		
		$this->draw_ticks();
		$this->draw_legend();
		
		// X axis
		imageLine($this->image, $gb->x, $gb->ymax(), $gb->xmax(), $gb->ymax(), $this->black);
		
		// Gridlines
		$gridy = pow(10, floor($this->log_min));
		for (; $gridy <= $this->data_bounds->ymax(); $gridy *= 10) {
			$this->draw_gridline($gridy);
			$this->draw_gridline($gridy * 2);
			$this->draw_gridline($gridy * 3);
			$this->draw_gridline($gridy * 5);
		}
		
		// Y axis
		imageLine($this->image, $gb->x, $gb->ymax(), $gb->x, $gb->y, $this->black);
	}
	
	function draw_gridline($y) {
		if ($y > $this->data_bounds->ymax() || $y < $this->data_bounds->y)
			return;
		
		$grey = 0xbb;
		$color = imageColorAllocate($this->image, $grey, $grey, $grey);
		$style = array_merge(
			array_fill(0, 4, IMG_COLOR_TRANSPARENT),
			array_fill(0, 1, $color)
		);
		imageSetStyle($this->image, $style);
		
		$yc = $this->ycoord($y);
		imageLine($this->image, $this->graph_bounds->x, $yc ,$this->graph_bounds->xmax(), $yc,
			IMG_COLOR_STYLED);
		
		// Draw the label
		$label = $this->pct ? "$y%" : $y;
		$bbox = imageTTFBBox($this->font_size, 0, $this->font, $label);
		$labelx = $this->graph_bounds->x - $bbox[2] - $this->label_pad;
		$labely = $yc - ($bbox[1] + $bbox[5]) / 2;
		imageTTFText($this->image, $this->font_size, 0, $labelx, $labely, $this->black,
			$this->font, $label);
	}
	
	function draw_ticks() {
		$y = $this->graph_bounds->ymax();
		$ticksz = 4;
		
		// About one tick every so many pix
		$nticks = round($this->graph_bounds->w / 150);
		$ticktime = (float)$this->data_bounds->w / $nticks;
		
		$curtime = $this->data_bounds->x;
		for ($i = 0; $i <= $nticks; ++$i) { // ticks at start AND end
			$x = ((float)$curtime - $this->data_bounds->x) / $this->data_bounds->w
				* $this->graph_bounds->w + $this->graph_bounds->x;
			imageLine($this->image, $x, $y + $ticksz, $x, $y - $ticksz, $this->black);
			
			$label = strftime("%Y-%m-%d", $curtime);
			$bbox = imageTTFBBox($this->font_size, 0, $this->font, $label);
			$labelx = $x - ($bbox[0] + $bbox[2]) / 2;
			$labely = $y - $bbox[5] + $this->label_pad + $ticksz;
			
			// Don't allow them over the right edge
			$labelx -= max(0, $labelx + $bbox[2] - $this->graph_bounds->xmax());
			
			imageTTFText($this->image, $this->font_size, 0, $labelx, $labely, $this->black,
				$this->font, $label);
			
			$curtime += $ticktime;
		}
	}
	
	function draw_legend() {
		// Discover text size. Use ascender and descender
		$bbox = imageTTFBBox($this->font_size, 0, $this->font, "fg");
		$this->text_height = $bbox[1] - $bbox[5];
		$this->text_mid = ($bbox[5] + $bbox[1]) / 2;
		
		// Generate legend items
		$groups = array();
		foreach ($this->order as $idx => $name) {
			$groups[] = new LegendGroup($this->label_pad, $this->text_height,
				$idx, $name, $this->ycoord($this->last[$name]));
		}
		
		// Try to arrange them so they don't collide
		$i = 1;
		while (isset($groups[$i])) {
			//$this->print_groups($i, $groups);
			if ($groups[$i-1]->overlaps($groups[$i])) {
				$groups[$i-1]->merge($groups[$i]);
				array_splice($groups, $i, 1);
				if ($i > 1) --$i;
			} else {
				++$i;
			}
		}
		
		// Draw them
		foreach ($groups as $group) {
			$this->draw_legend_group($group);
		}
	}
	
	function print_groups($idx, $groups) {
		error_log("--- $idx --------------------------");
		foreach ($groups as $i => $group) {
			error_log(sprintf("%2d: %5.1f - %5.1f   %d", $i, $group->min(),
				$group->max(), $group->count()));
		}
	}
	
	function draw_legend_group($group) {
		$y = $group->min() + $group->text_height / 2;
		foreach ($group->items as $item) {
			$this->draw_legend_item($item, $y);
			$y += $group->text_height + $group->pad;
		}
	}
	
	function draw_legend_item($item, $y) {
		$th = $this->text_height;
		
		// Draw a box
		$x = $this->graph_bounds->xmax() + 2 * $this->label_pad;
		imageFilledRectangle($this->image, $x, $y - $th / 2, $x + $th, $y + $th / 2,
				$this->palette($item['idx']));
		imageRectangle($this->image, $x, $y - $th / 2, $x + $th, $y + $th / 2, $this->black);
		
		// Draw the name
		$x += $th + $this->label_pad;
		imageTTFText($this->image, $this->font_size, 0, $x, $y - $this->text_mid,
			$this->black, $this->font, $item['name']);
	}
}

// Suppress warnings if no timezone defined
date_default_timezone_set(@date_default_timezone_get());

$graph = new PackageGraph(isset($_REQUEST['size']) ? $_REQUEST['size'] : '');
$graph->display();
