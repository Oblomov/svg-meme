# SVG Meme generator

## Synopsis

The main `src/meme.fcgi` is a rather simple Perl script to generate
image macros (from common memes —not included— as well as from any
‘base’ image you would care about). The script can be used from the
command-line as well as a CGI or FastCGI script (note: FastCGI untested
for the time being).

The script needs an index of ‘allowed’ base images (and their
dimensions, in pixel). The list can be generated with the attached
`gen-sizes.sh` shell script (relies on `identify` from ImageMagick).

CGI parameters are `m` for the base image name, `t` for the text, `sep`
for the separator and `fs` for the font size.

The default base image is taken to be the first in the hash of known
base images (and is therefore random for each script execution). The
default text is `TOP TEST//BOTTOM TEST`, the default separator is `/`
and the default font size is computed automatically based on a few image
and text properties.

The separator is used to split the text into multiple lines. Empty lines
act as ‘fillers’, so that the default sample text results in `TOP TEST`
on the first line and `BOTTOM TEST` at the bottom of the image.

The default font size can be overridden by specifying a single numerical
value in `fs`. The font size in individual lines can be overridden by
using an `fs` pattern that follows the line structure. E.g., `fs=90/`
will print the top line in size 90 and all other lines in the default
font size. Non-numeric (according to Perl) font sizes are ignored.

# Copyright

Copyright (C) 2013 Giuseppe Bilotta

# License

Distributed under the terms of the Artistic License, see `Artistic` in
the repository root.


