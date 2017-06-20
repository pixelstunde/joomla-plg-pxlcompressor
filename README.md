# Joomla! Plugin PXLCompressor 
## Image compression made simple
PXLCompressor is a free Joomla! plugin, which allows you to compress and resize every image uploaded with the media manager automatically. This is especially useful if the uploaded images are not preprocessed and allows you to speed up your website drastically.

### Features:

* Automagically resize every image uploaded with the media manager component on the fly
* Improve page speed by decreasing file sizes
* Chose a scale method according to your needs (scale inside / outside / fit / fill)
* Set compression levels for png/jpeg files
* Supported file formats: JPG, PNG and GIF
* Create thumbnails

Currently, the following image compression services are supported, but there might be more to come:

* ReSmush.it: a free image optimization service with unlimited number of compression. It supports the most common file types like (PNG, JPG, GIF, BMP and TIF). However: The JImage class of Joomla! framework does not seem to support TIF, so compression of .tif files is currently not enabled.
Only downside: the maximum size of a file is 5MB, but if you enable resizing, which is done before, it is very unlikely that you will ever reach this size
* TinyPNG: the web service allows you to compress your PNG and JPG files. You can subscribe for a free plan enabling you to compress 500 images per month without any charges. Simply fill in your API key to get started.
If you enable both compression services and TinyPNG fails for any reason, ReSmush will be used as a fallback.

It is planned to support local compression using optipng/gisicle/jpegoptim in later versions.

Special thanks to Viktor Vogel for creating the plugin EIR, from which I took larger parts of the code.
