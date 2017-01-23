var gulp = require('gulp');
var source = require('vinyl-source-stream');
var browserify = require('browserify');

var debug = false;
var vendors = [
    "ampersand-router",
    "bootstrap",
    "fixed-data-table",
    "graphlib-dot",
    "marked",
    "moment",
    "react",
    "react-addons-shallow-compare",
    "react-bootstrap",
    "react-dates",
    "react-dom",
    "react-measure",
    "react-string-replace",
    "springy"
    //'superagent'
];

/**
 * Compile vendor
 */
gulp.task('js-vendor', function () {
    var b = browserify({
        'debug': debug
    });

    vendors.forEach(function(vendor){
        b.require(vendor);
    });

    return b
        .bundle()
        .pipe(source('vendor.js'))
        .pipe(gulp.dest('./public/'))
        .on('error', console.log);
});

/**
 * Compile client code
 */
gulp.task('js', function () {
    var b = browserify({
        entries: './client/main.js',
        'debug': debug
    });

    vendors.forEach(function(vendor){
        b.exclude(vendor);
    });

    return b
        .transform("babelify", {presets: ["es2015", "react"]})
        .bundle()
        .pipe(source('app.js'))
        .pipe(gulp.dest('./public/'))
        .on('error', console.log);
});

gulp.task('less', function () {
    gulp.src('./less/main.less')
        .pipe(less())
        .pipe(gulp.dest('./public'));
});

gulp.task('less-watch', function () {
    gulp.watch(['./less/*.less'], ['less'])
});

gulp.task('js-watch', function () {
    gulp.watch(['./client/**.js'], ['js'])
});

gulp.task('watched', ['less', 'js', 'less-watch' ,'js-watch'], function(){});

gulp.task('build', ['js', 'js-vendor'], function(){});