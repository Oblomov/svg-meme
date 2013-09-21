#!/bin/sh
# Generate a `meme-sizes.lst` file containing width, height and filename for
# every .jpg file in the current directory.
#
# This file is read by the SVG meme generator to gather information
# about known meme bases.

identify -format "%w %h %f\n" *.jpg > meme-sizes.lst
