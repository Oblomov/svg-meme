#!/usr/bin/perl -w

# SVG meme generator
# Copyright (C) 2013 Giuseppe Bilotta
# Distributed under the terms of the Artistic License,
# see 'Artistic' in the repository root.

use strict;

use CGI::Fast;

use HTML::Entities;

use File::Basename;

use Scalar::Util qw(looks_like_number);

my %sizes; # meme base image sizes
my (%acros, %revacros); # meme base acronyms (BLB => bad-luck-brian.jpg) and reverse

# Are we running as CGI or from the command-line?
# TODO better detection
my $is_cgi = $ENV{'SCRIPT_FILENAME'};

my $script_path =  $is_cgi ? dirname($ENV{'SCRIPT_FILENAME'}) : dirname($0);

my $sz_fname = $script_path . '/meme-sizes.lst';

sub load_sizes() {
	open FILE, $sz_fname or die $!;

	while (my $line = <FILE>) {
		chomp($line);
		next unless $line;
		my ($width, $height, $fname) = split(/ /, $line, 3);
		$sizes{$fname} = [$width, $height];

		# Find a potential short form (acronym for multiword, no extension otherwise)
		my $acro = '';

		# remove article for the purpose of the shortening; we don't care if it's
		# in the middle of a word because we only care about initials anyway
		# FIXME this actually fails in the case of XXXthe-XXX, let's care about that
		# when we actually come across it
		my $the = $fname;
		$the =~ s/the-//g;
		if ($the =~ /-/) {
			$acro = join('', map { uc(substr($_, 0, 1)) } split(/-/, $the ));
		} else {
			$acro = (split(/\./, $the))[0]
		}
		if (!defined $acros{$acro}) {
			$acros{$acro} = $fname;
			$revacros{$fname} = $acro;
		} else {
			print STDERR "Trying to redefined acronym $acro from $acros{$acro} to ${fname}\n";
		}
	}

	close FILE;
}


# params: img, width, height, font-size, text

my $svg_template=<<SVG;
<?xml version='1.0' encoding='UTF-8'?>
<svg
 xmlns='http://www.w3.org/2000/svg'
 xmlns:xlink='http://www.w3.org/1999/xlink' version='1.1'
 viewBox='0 0 %2\$d %3\$d'>
<style type="text/css">text{font-family:'Impact';font-size:%4\$dpx;fill:white;stroke:black;stroke-width:2px;text-anchor:middle}</style>
<image xlink:href='%1\$s' x='0' y='0'
width='%2\$d' height='%3\$d'/>
%5\$s</svg>
SVG

sub fill_svg(%) {
	my %p = @_;
	return sprintf($svg_template,
		$p{img}, $p{width}, $p{height}, $p{fs}, $p{text});
}

# params: text, y-pos, font-size
# the font-size presented here is optional, and should only be
# present when this text line has a different font-size than
# the default one

my $txt_template=<<TXT;
<text x='50%%' y='%2\$d%%'%3\$s
>%1\$s</text>
TXT

sub fill_txt($%) {
	my $text = shift;
	my %p = @_;
	return sprintf($txt_template, $text, $p{y}, $p{linefs});
}

# routine to actually prepare the SVG.
# params: img, sep, text
sub make_svg(%) {
	my %p = @_;

	unless (defined $sizes{$p{img}}) {
		return undef unless defined $acros{$p{img}};
		$p{img} = $acros{$p{img}};
	}

	($p{width}, $p{height}) = @{$sizes{$p{img}}};

	$p{sep} = qr/\Q$p{sep}\E/;

	# font size specification is default:line/per/line/override
	# Non-numeric values are skipped
	my $fs_override;
	my @fss; # array of font-size overrides

	($p{fs}, $fs_override) = split(/:/, $p{fs},2);
	if (defined $p{fs} and $p{fs} =~ $p{sep}) {
		# there's a / in the default part. is this because there is no override part?
		if (!defined $fs_override) {
			$fs_override = $p{fs};
		}
		$p{fs} = undef;
	}

	if (defined $fs_override) {
		foreach (split($p{sep}, $fs_override, -1)) {
			push @fss, looks_like_number($_) ? $_ : '';
		}
	}

	my @lines; # text lines
	foreach (split($p{sep}, $p{text}, -1)) {
		push @lines, $_;
	}

	my $divisions = 7;
	$divisions = @lines if @lines > $divisions;

	# if the user specified a single font-size, use that, otherwise
	# compute a default one based on the number of divisions
	if (defined $p{fs} and $p{fs}) {
		$divisions = int($p{height}/$p{fs} + 0.5);
	} else {
		$p{fs} = int($p{height}/$divisions + 0.5);
		if ($p{fs} > $p{width}/10) {
			$p{fs} = int($p{width}/10 + 0.5) if $p{fs} > $p{width}/10;
			$divisions = int($p{height}/$p{fs} + 0.5);
		}
	}

	# formatted lines: each element is a ref to an array with the following elements:
	#  * line text (undef for empty line),
	#  * font size (undef for empty line or default font),
	#  * y increment (always defined)

	my @fmt_lines;
	my $total_height = 0; # total height of real lines
	my $fillers = 0; # number of fillers
	for (my $i = 0; $i < @lines; ++$i) {
		my $line = $lines[$i];
		my $fs = $fss[$i];
		my $lh = undef;
		if (defined $line and $line eq '') {
			$line = undef;
			++$fillers;
		}
		# let's have an actually defined font-size for purposes of height
		# computation
		if (!defined $fs or $fs eq '') {
			$fs = $p{fs};
		}
		if (defined $line) {
			$lh = int(100*$fs/$p{height} + 0.5);
			$total_height += $lh;
		}
		# undefine $fs if not needed
		if (!defined $line or $fs == $p{fs}) {
			$fs = undef;
		}
		push @fmt_lines, [$line, $fs, $lh];
	}
	my $filler_size = $fillers? int((98 - $total_height)/$fillers) : 0;

	if ($filler_size) {
		foreach (@fmt_lines) {
			$_->[2] = $filler_size unless defined $_->[2];
		}
	}

	$p{y} = 0;
	$p{text} = '';
	# iterate over both @lines and @fss. I'm sure there's a more perlish
	# way to do it
	foreach (@fmt_lines) {
		my $line = $_->[0];
		my $fs = $_->[1];
		my $lh = $_->[2];

		$p{y} += $lh;
		next unless defined $line; #fillers just increment y

		if (not defined $fs) {
			$p{linefs} = '';
		} else {
			# Damn, apparently attribute font-size does not ovverride style
			# $p{linefs} = " font-size='$fs'";
			$p{linefs} = " style='font-size:${fs}px'";
		}

		$p{text} .= fill_txt($line, %p);
	}

	return fill_svg(%p);
}

my %p;

load_sizes();

while (my $q = new CGI::Fast) {

	my (@t, @fs);

	if ($is_cgi) {
		$p{img} = $q->param('m');
		$p{sep} = $q->param('s');
		@fs = $q->param('fs');
		@t = $q->param('t');
	} else {
		# TODO specify font-size from CLI
		($p{img}, $p{sep}, @t) = @ARGV;
	}

	$p{sep} ||= '/';
	$p{fs} = join($p{sep}, @fs);
	$p{text} = join($p{sep}, @t);

	$p{img} ||= (keys %sizes)[0];
	$p{text}||= 'TOP TEST//BOTTOM TEST';

	my $svg = make_svg(%p);

	if (defined $svg) {
		print $q->header(
			-type => 'image/svg+xml',
			-charset => 'UTF-8'
		) if $is_cgi;

		print $svg;

		next if $is_cgi;
		exit 0;
	}

	# missing meme
	if ($is_cgi) {
		print $q->header(-status=>404),
		$q->start_html("Unknown meme base"),
		$q->h1("Unknown meme base!");
		print	"<p>Sorry, <tt>'" . encode_entities($p{img}) . "'</tt> is not a known meme base. ".
		"You want one of the following instead:</p><ul>";
		foreach (keys %sizes) {
			print "<li><tt>" . encode_entities($_) . "</tt>";
			print " (<tt>" . encode_entities($revacros{$_}) . "</tt>)" if defined $revacros{$_};
			print "</tt></li>";
		}
		print "</ul>";
		# foreach (keys %ENV) {
		# 	print "<p>$_=$ENV{$_}</p>"
		# }
		print $q->end_html();
		next;
	} else {
		foreach (keys %sizes) {
			print STDERR "* $_";
			print STDERR " ($revacros{$_})" if defined $revacros{$_};
			print STDERR "\n";
		}
		exit -1;
	}
}
