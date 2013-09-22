#!/usr/bin/perl -w

use strict;

use CGI::Fast;

use HTML::Entities;

use File::Basename;

my %sizes; # meme base image sizes
my %acros; # meme base acronyms (BLB => bad-luck-brian.jpg)

my $script_path = $ENV{'SCRIPT_FILENAME'} ? dirname($ENV{'SCRIPT_FILENAME'}) : dirname($0);

my $sz_fname = $script_path . '/meme-sizes.lst';

open FILE, $sz_fname or die $!;

while (my $line = <FILE>) {
	chomp($line);
	next unless $line;
	my ($width, $height, $fname) = split(/ /, $line, 3);
	$sizes{$fname} = [$width, $height];

	# Find a potential short form (acronym for multiword, no extension otherwise)
	my $acro = '';

	# remove article for the purpose of the shrotening; we don't care if it's
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
	} else {
		print STDERR "Trying to redefined acronym $acro from $acros{$acro} to ${fname}\n";
	}
}

close FILE;


# params: img, width, height, text

my $svg_template=<<SVG;
<?xml version='1.0' encoding='UTF-8'?>
<svg
 xmlns='http://www.w3.org/2000/svg'
 xmlns:xlink='http://www.w3.org/1999/xlink' version='1.1'
 viewBox='0 0 %2\$d %3\$d'>
<style type="text/css">text{font-family:'Impact';fill:white;stroke:black;stroke-width:2px;text-anchor:middle}</style>
<image xlink:href='%1\$s' x='0' y='0'
width='%2\$d' height='%3\$d'/>
%4\$s</svg>
SVG

# template: y-pos, font-size, text
my $txt_template=<<TXT;
<text x='50%%' y='%1\$d%%' font-size='%2\$d'
>%3\$s</text>
TXT

while (my $q = new CGI::Fast) {
	my $img = $q->param('m') || (keys %sizes)[0];
	if (!defined $sizes{$img}) {
		if (!defined $acros{$img}) {
			print $q->header(-status=>404),
			$q->start_html("Unknown meme base"),
			$q->h1("Unknown meme base!");
			print	"<p>Sorry, <tt>'" . encode_entities($img) . "'</tt> is not a known meme base. ".
			"You want one of the following instead:</p><ul>";
			my %revacros = reverse %acros;
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
			$img = $acros{$img};
		}
	}

	print $q->header(
		-type => 'image/svg+xml',
		-charset => 'UTF-8'
	);

	my ($width, $height) = @{$sizes{$img}};

	my $sep = $q->param('s') || '/'; # line separator

	my @t = $q->param('t') || ('TEST TOP//TEST BOTTOM');

	my $divisions = 7;
	my @lines = ();
	foreach (@t) {
		foreach (split /\Q$sep\E/) {
			push @lines, $_;
		}
	}

	$divisions = @lines if @lines > $divisions;

	my $fontsize = int($height/$divisions + 0.5);
	if ($fontsize > $width/10) {
		$fontsize = int($width/10 + 0.5) if $fontsize > $width/10;
		$divisions = int($height/$fontsize + 0.5);
	}

	my $offset = int(100/$divisions + 0.5);
	my $fillers = grep { $_ eq '' } @lines;
	my $real_lines = @lines - $fillers;
	my $filler_size = $fillers ? int((98 - $offset*$real_lines)/$fillers) : 0;

	my $dy = 0;
	my $txt = '';
	foreach (@lines) {
		if ($_ eq '') {
			$dy += $filler_size;
			next;
		}
		$dy += $offset;
		$txt .= sprintf($txt_template, $dy, $fontsize, $_);
	}

	printf($svg_template, $img, $width, $height, $txt);
}
