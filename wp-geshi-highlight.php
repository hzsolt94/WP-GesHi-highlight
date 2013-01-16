<?php
/*
Plugin Name: WP-GeSHi-Highlight
Plugin URI: http://gehrcke.de/wp-geshi-highlight/
Description: Fast syntax highlighting for many languages based on GeSHi, the well-established and award-winning highlighter for PHP. Produces clean, small, and valid (X)HTML output. WP-GeSHi-Highlight is easily configurable.
Author: Jan-Philip Gehrcke
Version: 1.0.8
Author URI: http://gehrcke.de

WP-GeSHi-Highlight is a largely changed and improved version of WP-Syntax by
Ryan McGeary (http://ryan.mcgeary.org/): wordpress.org/extend/plugins/wp-syntax/
Code parts taken from WP-Syntax are marked correspondingly.

################################################################################
#   Contact: http://gehrcke.de -- jgehrcke@googlemail.com
#
#   Copyright (C) 2010-2013 Jan-Philip Gehrcke
#   Copyright (C) 2007-2009 Ryan McGeary (only the tagged code parts)
#
#   This file is part of WP-GeSHi-Highlight.
#   You can use, modify, redistribute this program under the terms of the GNU
#   General Public License Version 2 (GPL2): http://www.gnu.org/licenses.
#       This program is distributed in the hope that it will be useful, but
#   WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
#   or FITNESS FOR A PARTICULAR PURPOSE (cf. GPL2).
#
################################################################################

Advantages over comparable highlighters
=======================================
- WP-GeSHi-Highlight filters & replaces code snippets as early as possible. The
  highlighted code is inserted as late as possible. Hence, interference with
  other plugins is minimized.
- If it does not find any code snippet, it does not waste performance.

Usage of GeSHi setting "GESHI_HEADER_PRE_VALID"
-----------------------------------------------
- Makes usage of numbered lists for line numbering.
    ->  number-source shiftings never occur. Methods relying on tables for
        number-source alignment often fail when showing long code blocks.
        I had this problem with the original version of WP-Syntax.
        Certain pastebins have this problem.
- Creates valid (X)HTML code. Not trivial while using ordered lists for line
  numbers. The challenge is discussed in many bugreports, e.g.
  http://bit.ly/bks1Uu

Usage of GeSHi's get_stylesheet()
---------------------------------
- Creates hort highlighting html code: styling is not based on long 
    <span style"........."> ocurrences.

Possible issues
===============
- Snippet search and replacement is based on PHP's `preg_replace_callback()`.
"The pcre.backtrack_limit option (added in PHP 5.2) can trigger a NULL return,
with no errors."
http://www.php.net/manual/de/function.preg-replace-callback.php#98721
http://www.php.net/manual/de/function.preg-replace-callback.php#100303
This means that for very long code snippets, it might happen that this function
simply does not find/replace anything. These snippets won't get highlighted.
For me this never was an issue, please let me know if you experience this.

- The "line" argument allows for numbers greater than 1. This starts the
    numbering at the given number. And it breaks XHTML validity.

This is how the plugin works for all page requests
==================================================
I) template_redirect hook:
--------------------------
1)  The user has sent a request. Wordpress has set up its `$wp_query` object.
   `$wp_query` contains information about all content potentially shown to the
    user.
2)  This plugin iterates over this content, i.e. over each post, including each
    (approved) comment belonging to this post.
3)  While iterating over the post and comment texts, occurrences of the pattern
    <pre args>CODE</pre> are searched.
4)  If one such pattern is found, the information (args and CODE basically)
    is stored safely in a global variable, together with a match index.
    
This was the fixed part at the beginning of each page request. The next steps
only happen if there was actually a code snippet to highlight.    
    
5)  Furthermore, the occurrence of the pattern in the original content
    (post/comment text) is deleted and replaced by a unique identifier
    containing the corresponding match index. Therefore, the content cannot be
    altered by any other WordPress plugin afterwards.
6)  GeSHi iterates over all code snippets and generates HTML code for each
    snippet according to the given programming language line numbering setting.
7)  Additionally, GeSHi generates optimized CSS code for each snippet. All CSS
    code generated by GeSHi ends up in one string.
8)  For each code snippet, the HTML code and the corresponding match index is
    stored safely in a global variable.


II) wp_head hook:
-----------------
Within this hook, the plugin tells WordPress to print two strings to the <head>
section of the HTML code:
  a) include wp-geshi-highlight.css (if available in the plugin directory).
     This is for general styling of code blocks. If required, other CSS files
     are included, too.
  b) All CSS code generated by GeSHi is included.


III) content filters:
---------------------
1)  The plugin defines three very low priority filters on post text and
    excerpt and comment text. This means these filters run after all or most
    other plugins have done their job, i.e. shortly before the html code is
    delivered to the user's browser.
2)  These filters look for the unique identifiers including the match indices as
    inserted in I.5.
3)  If such an identifier is found, it gets replaced by the corresponding
    highlighted code snippet.
*/


// Entry point of the plugin (right after WordPress finished processing the 
// user request, set up `$wp_query`, and right before the template renders
// the HTML output).
add_action('template_redirect', 'wp_geshi_main');


function wp_geshi_main() {
    // Initialize variables.
    global $wp_geshi_codesnipmatch_arrays;
    global $wp_geshi_run_token;
    global $wp_geshi_comments;
    global $wp_geshi_used_languages;
    global $wp_geshi_requested_css_files;
    $wp_geshi_requested_css_files = array();
    $wp_geshi_comments = array();
    $wp_geshi_used_languages = array();

    // Generate unique token. Code snippets will temporarily be replaced by it
    // (+snip ID).
    $wp_geshi_run_token = md5(uniqid(rand())); // (C) Ryan McGeary

    // Filter all post/comment texts and save and replace code snippets.
    wp_geshi_filter_and_replace_code_snippets();

    // If we did not find any code snippets, it's time to leave...
    if (!count($wp_geshi_codesnipmatch_arrays)) return;

    // `$wp_geshi_codesnipmatch_arrays` is populated. Process it: it's now
    // GeSHi's turn: generate HTML and CSS code.
    wp_geshi_highlight_and_generate_css();

    // Now, `$wp_geshi_css_code` and `$wp_geshi_highlighted_matches` are set.
    // Add action to add CSS code to HTML header.
    add_action('wp_head', 'wp_geshi_add_css_to_head');

    // In `wp_geshi_filter_and_replace_code_snippets()` the comments have been
    // queried, filtered and stored in `$wp_geshi_comments`. But, in contrast to
    // the posts, the comments become queried again when `comments_template()`
    // is called by the theme -> comments are read two times from the database.
    // No way to prevent this if the comments' content should be available
    // before wp_head. After the second read, all changes -- and with that the
    // "uuid replacement" -- are lost. The `comments_array` filter becomes
    // triggered and can be used to set all comments to the state after the
    // first filtering by wp-geshi-highlight (as saved in `$wp_geshi_comments`).
    // --> Add high priority filter to replace comments with the ones stored in
    // `$wp_geshi_comments`.
    add_filter('comments_array', 'wp_geshi_insert_comments_with_uuid', 1);

    // Add low priority filter to replace unique identifiers with highlighted
    // code.
    add_filter('the_content', 'wp_geshi_insert_highlighted_code_filter', 99);
    add_filter('the_excerpt', 'wp_geshi_insert_highlighted_code_filter', 99);
    add_filter('comment_text', 'wp_geshi_insert_highlighted_code_filter', 99);
    }


// Parse all post and comment texts connected to the query.
// While iterating over these texts, do the following:
// - Detect <pre args> code code code </pre> patterns.
// - Save these patterns in a global variable.
// - Modify post/comment texts: replace code patterns by a unique token.
function wp_geshi_filter_and_replace_code_snippets() {
    global $wp_query;
    global $wp_geshi_comments;
    // Iterate over all posts in this query.
    foreach ($wp_query->posts as $post) {
        // Extract code snippets from the content. Replace them.
        $post->post_content = wp_geshi_filter_replace_code($post->post_content);
        // Iterate over all approved comments belonging to this post.
        // Store comments with uuid (code replacement) in `$wp_geshi_comments`.
        $comments = get_approved_comments($post->ID);
        foreach ($comments as $comment) {
            $wp_geshi_comments[$comment->comment_ID] =
                wp_geshi_filter_replace_code($comment->comment_content);
            }
        }
    }


// Called from a filter replacing comments coming from the second read
// of the database with the ones stored in `$wp_geshi_comments`.
function wp_geshi_insert_comments_with_uuid($comments_2nd_read) {
    global $wp_geshi_comments;
    // Iterate over comments from 2nd read. Call by reference, otherwise changes
    // have no effect.
    foreach ($comments_2nd_read as &$comment) {
        if (array_key_exists($comment->comment_ID, $wp_geshi_comments)) {
            // Replace the comment content from 2nd read with the content
            // that was created after the 1st read.
            $comment->comment_content =
                $wp_geshi_comments[$comment->comment_ID];
            }
        }
    return $comments_2nd_read;
    }


// Search for all <pre args>code</pre> occurrences. Save them globally.
// Replace them with unambiguous identifiers (uuid).
// Call `wp_geshi_substitute($match)` for each match.
// A `$match` is an array, following the sub-pattern of the regex:
// 0: all
// 1: language
// 2: line
// 3: escaped
// 4: cssfile (a filename without .css suffix)
// 5: code
function wp_geshi_filter_replace_code($s) {
    return preg_replace_callback(
        "/\s*<pre(?:lang=[\"']([\w-]+)[\"']|line=[\"'](\d*)[\"']"
        ."|escaped=[\"'](true|false)?[\"']|cssfile=[\"']([\S]+)[\"']|\s)+>".
        "(.*)<\/pre>\s*/siU",
        "wp_geshi_store_and_substitute",
        $s
        );
    }


// Store code snippet data. Return unambiguous identifier for this code snippet.
function wp_geshi_store_and_substitute($match_array) {
    global $wp_geshi_run_token, $wp_geshi_codesnipmatch_arrays;

    // count() returns 0 if the variable is not set already.
    // Index is required for building the identifier of this code snippet.
    $match_index = count($wp_geshi_codesnipmatch_arrays);

    // Elements of `$match_array` are strings matching the sub-expressions in
    // the regular expression searching <pre args>code</pre> (in function
    // `wp_geshi_filter_replace_code()`. They contain the arguments of the
    // <pre> tag and the code snippet itself. Store this array for later usage.
    // Append the match index to `$match_array`.
    $match_array[] = $match_index;
    $wp_geshi_codesnipmatch_arrays[$match_index] = $match_array;

    // Return a string that unambiguously identifies the match.
    // This string replaces the whole <pre args>code</pre> code pattern.
    return "\n<p>".$wp_geshi_run_token."_".
        sprintf("%06d",$match_index)."</p>\n"; // (C) Ryan McGeary
    }


// Iterate over all match arrays in `$wp_geshi_codesnipmatch_arrays`.
// Perform syntax highlighting and store the resulting string back in
// `$wp_geshi_highlighted_matches[$match_index]`.
// Generate CSS code and append it to global `$wp_geshi_css_code`.
function wp_geshi_highlight_and_generate_css() {
    global $wp_geshi_codesnipmatch_arrays;
    global $wp_geshi_css_code;
    global $wp_geshi_highlighted_matches;
    global $wp_geshi_requested_css_files;
    global $wp_geshi_used_languages;

    // When we're here, code was found.
    // Time to initialize the highlighting machine...
    
    // Check for `class_exists('GeSHi')` for preventing
    // `Cannot redeclare class GeSHi` errors. Another plugin may already have 
    // included its own version of GeSHi.
    // TODO: in this case, include GeSHi of WP-GeSHi-Highlight anyway via using
    // namespaces or class renaming.
    if (!class_exists('GeSHi')) include_once("geshi/geshi.php");
    $wp_geshi_css_code = "";
    foreach($wp_geshi_codesnipmatch_arrays as $match_index => $match) {
            // Process the match details. The correspondence is explained in
            // function `wp_geshi_filter_replace_code()`.
            $language = strtolower(trim($match[1]));
            $line = trim($match[2]);
            $escaped = trim($match[3]);
            $cssfile = trim($match[4]);
            $code = wp_geshi_code_trim($match[5]);
            if ($escaped == "true")
                $code = htmlspecialchars_decode($code); // (C) Ryan McGeary

            // Set up GeSHi.
            $geshi = new GeSHi($code, $language);
            // Prepare GeSHi to output CSS code and to prohibit inline styles.
            $geshi->enable_classes();
            // Disable keyword links.
            $geshi->enable_keyword_links(false);

            // Process the line number option given by user.
            if ($line) {
                $geshi->enable_line_numbers(GESHI_NORMAL_LINE_NUMBERS);
                $geshi->start_line_numbers_at($line);
                }

            // Set the output code type.
            $geshi->set_header_type(GESHI_HEADER_PRE_VALID);

            // Append the CSS code to the CSS code string if this is the first
            // occurrence of the language. $geshi->get_stylesheet(false)
            // disables the economy mode, i.e. this will return  the full CSS
            // code for the given language. This makes it much easier to use the
            // same CSS code for several code blocks of the same language.
            if  (!in_array($language, $wp_geshi_used_languages)) {
                $wp_geshi_used_languages[] = $language;
                $wp_geshi_css_code .= $geshi->get_stylesheet();
                }

            $output = "";
            // cssfile "none" means no wrapping divs at all.
            if ($cssfile != "none") {
                if (empty($cssfile))
                    // For this code snippet the default css file is required.
                    $cssfile = "wp-geshi-highlight";
                // Append "the css file" to the array.
                $wp_geshi_requested_css_files[] = $cssfile;
                $output .= "\n\n".'<div class="'.$cssfile.'-wrap5">'.
                           '<div class="'.$cssfile.'-wrap4">'.
                           '<div class="'.$cssfile.'-wrap3">'.
                           '<div class="'.$cssfile.'-wrap2">'.
                           '<div class="'.$cssfile.'-wrap">'.
                           '<div class="'.$cssfile.'">';
                }
            $output .= $geshi->parse_code();
            if ($cssfile != "none")
                $output .= '</div></div></div></div></div></div>'."\n\n";
            $wp_geshi_highlighted_matches[$match_index] = $output;
        }
    // At this point, all code snippets are parsed. Highlighted code is stored.
    // CSS code is generated. Delete variables that are not required anymore.
    unset($wp_geshi_codesnipmatch_arrays);
    }


function wp_geshi_insert_highlighted_code_filter($content){
    global $wp_geshi_run_token;
    return preg_replace_callback(
        "/<p>\s*".$wp_geshi_run_token."_(\d{6})\s*<\/p>/si",
        "wp_geshi_get_highlighted_code",
        $content
        ); // (C) Ryan McGeary
    }


function wp_geshi_get_highlighted_code($match) {
    global $wp_geshi_highlighted_matches;
    // Found a unique identifier. Extract code snippet match index.
    $match_index = intval($match[1]);
    // Return corresponding highlighted code.
    return $wp_geshi_highlighted_matches[$match_index];
    }


function wp_geshi_code_trim($code) {
    // Special ltrim because leading whitespace matters on 1st line of content.
    $code = preg_replace("/^\s*\n/siU", "", $code); // (C) Ryan McGeary
    $code = rtrim($code); // (C) Ryan McGeary
    return $code;
    }


function wp_geshi_add_css_to_head() {
    global $wp_geshi_css_code;
    global $wp_geshi_requested_css_files;

    echo "\n<!-- WP-GeSHi-Highlight plugin by ".
         "Jan-Philip Gehrcke: http://gehrcke.de -->\n";

    // Set up paths and names.
    $csspathpre = WP_PLUGIN_DIR."/wp-geshi-highlight/";
    $cssurlpre = WP_PLUGIN_URL."/wp-geshi-highlight/";
    $csssfx = ".css";

    // Echo all required CSS files; delete duplicates.
    $wp_geshi_requested_css_files = array_unique($wp_geshi_requested_css_files);
    foreach($wp_geshi_requested_css_files as $cssfile)
        wp_geshi_echo_cssfile($csspathpre.$cssfile.$csssfx,
            $cssurlpre.$cssfile.$csssfx);
    // Echo GeSHi CSS code if required.
    if (strlen($wp_geshi_css_code) > 0)
        echo '<style type="text/css"><!--'.
            $wp_geshi_css_code."//--></style>\n";
    }


function wp_geshi_echo_cssfile($path, $url) {
    // Only link a CSS file if its corresponding path is valid.
    if (file_exists($path)) {
        echo '<link rel="stylesheet" href="'.$url.
             '" type="text/css" media="screen" />'."\n";
        }
    }


// Set allowed attributes for pre tags. For more info see wp-includes/kses.php
// credits: wp-syntax (Ryan McGeary)
if (!CUSTOM_TAGS) {
    $allowedposttags['pre'] = array(
        'lang' => array(),
        'line' => array(),
        'escaped' => array(),
        'cssfile' => array()
    );
  // Allow plugin use in comments.
    $allowedtags['pre'] = array(
        'lang' => array(),
        'line' => array(),
        'escaped' => array(),
        'cssfile' => array()
    );
}
?>