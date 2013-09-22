#!/usr/bin/perl -w

use strict;

use CGI::Fast;

use HTML::Entities;

use File::Basename;

use Scalar::Util qw(looks_like_number);

my %sizes; # meme base image sizes
my %acros; # meme base acronyms (BLB => bad-luck-brian.jpg)

# Are we running as CGI or from the command-line?
# TODO better detection
my $is_cgi = $ENV{'SCRIPT_FILENAME'};

my $script_path =  $is_cgi ? dirname($ENV{'SCRIPT_FILENAME'}) : dirname($0);

my $sz_fname = $script_path . '/meme-sizes.lst';

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
	} else {
		print STDERR "Trying to redefined acronym $acro from $acros{$acro} to ${fname}\n";
	}
}

my %revacros = reverse %acros;

close FILE;


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
sub make_svg($@) {
	my $pref = shift;
	my %p = %{$pref};
	my @t   = @_;

	unless (defined $sizes{$p{img}}) {
		return undef unless defined $acros{$p{img}};
		$p{img} = $acros{$p{img}};
	}

	($p{width}, $p{height}) = @{$sizes{$p{img}}};

	$p{sep} = qr/\Q$p{sep}\E/;

	$p{fs} = ['90//'];
	my @fss; # line-specific font sizes. Kill non-numeric values
	foreach (@{$p{fs}}) {
		foreach (split($p{sep}, $_, -1)) {
			push @fss, looks_like_number($_) ? $_ : '';
		}
	}

	my @lines; # text lines
	foreach (@t) {
		foreach (split($p{sep}, $_, -1)) {
			push @lines, $_;
		}
	}

	my $divisions = 7;
	$divisions = @lines if @lines > $divisions;

	# if the user specified a single font-size, use that, otherwise
	# compute a default one based on the number of divisions
	if (@fss == 1) {
		$p{fs} = $fss[0];
		$divisions = int($p{height}/$p{fs} + 0.5);
	} else {
		$p{fs} = int($p{height}/$divisions + 0.5);
		if ($p{fs} > $p{width}/10) {
			$p{fs} = int($p{width}/10 + 0.5) if $p{fs} > $p{width}/10;
			$divisions = int($p{height}/$p{fs} + 0.5);
		}
	}

	# TODO adjust filler size when some lines have different heights
	my $offset = int(100/$divisions + 0.5);
	my $fillers = grep { $_ eq '' } @lines;
	my $real_lines = @lines - $fillers;
	my $filler_size = $fillers ? int((98 - $offset*$real_lines)/$fillers) : 0;

	# zip @lines and @fss, removing default font-sizes from @fss
	if (@fss == 1) {
		@fss = ();
	} else {
		@fss = map { $_ eq '' || $_ == $p{fs} ? undef : $_ } @fss;
	}

	$p{y} = 0;
	$p{text} = '';
	# iterate over both @lines and @fss. I'm sure there's a more perlish
	# way to do it
	for (my $i = 0; $i < @lines; ++$i) {
		my $line = $lines[$i];
		my $fs = $fss[$i];
		if ($line eq '') {
			$p{y} += $filler_size;
			next;
		}
		if (not defined $fs) {
			$p{linefs} = '';
			$p{y} += $offset;
		} else {
			# Damn, apparently attribute font-size does not ovverride style
			# $p{linefs} = " font-size='$fs'";
			$p{linefs} = " style='font-size:${fs}px'";
			$p{y} += int(100*$fs/$p{height});
		}
		$p{text} .= fill_txt($line, %p);
	}

	return fill_svg(%p);
}

my (%p, @t);

while (my $q = new CGI::Fast) {

	if ($is_cgi) {
		$p{img} = $q->param('m');
		$p{sep} = $q->param('s');
		@t = $q->param('t');
		# ugh
		my @fss = $q->param('fs');
		$p{fs} = \@fss;
	} else {
		# TODO specify font-size from CLI
		($p{img}, $p{sep}, @t) = @ARGV;
	}

	$p{img} ||= (keys %sizes)[0];
	$p{sep} ||= '/';
	@t = ('TOP TEST//BOTTOM TEST') unless @t;

	my $svg = make_svg(\%p, @t);

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
