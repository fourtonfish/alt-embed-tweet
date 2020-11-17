<?php
/**
 * Plugin Name: TEmbeds
 * Plugin URI: https://github.com/fourtonfish/tweet-embeds-wordpress-plugin
 * Description: Embed Tweets without compromising your users' privacy and your site's performance.
 * Version: 1.0.2
 * Author: fourtonfish
 *
 * @package ftf-alt-embed-tweet
 */

defined( 'ABSPATH' ) || exit;

class FTF_Alt_Embed_Tweet {
    function __construct(){
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_ftf_embed_tweet', array( $this, 'embed_tweet' ), 1000 );
        add_action( 'wp_ajax_nopriv_ftf_embed_tweet', array( $this, 'embed_tweet' ), 1000 );
        add_action( 'admin_init', array( $this, 'settings_init' ) );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'render_block', array( $this, 'remove_twitter_script' ), 10, 2 );
        add_filter( 'plugin_action_links_tembeds/index.php', array( $this, 'settings_page_link' ) );
    }

    function create_bearer_token( $twitter_api_consumer_key, $twitter_api_consumer_secret ){
        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $twitter_api_consumer_key . ':' . $twitter_api_consumer_secret )
            ),
            'body' => array( 'grant_type' => 'client_credentials' )
        );

        $response = wp_remote_post( 'https://api.twitter.com/oauth2/token', $args );

        return json_decode( $response['body'] );
    }

    function call_twitter_api( $endpoint = 'account/verify_credentials', $data = null ){
        $version = '2';
        $data = array();

        $twitter_api_consumer_key = get_option( 'ftf_alt_embed_tweet_twitter_api_consumer_key' );
        $twitter_api_consumer_secret = get_option( 'ftf_alt_embed_tweet_twitter_api_consumer_secret' );
        $twitter_api_oauth_access_token = get_option( 'ftf_alt_embed_tweet_twitter_api_oauth_access_token' );
        $twitter_api_oauth_access_token_secret = get_option( 'ftf_alt_embed_tweet_twitter_api_oauth_access_token_secret' );

        $token = self::create_bearer_token( $twitter_api_consumer_key, $twitter_api_consumer_secret );
        $api_endpoint = 'https://api.twitter.com/' . $version . '/' . $endpoint;

        if ( isset( $token->token_type ) && $token->token_type == 'bearer' ){

            $args = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token->access_token
                )
            );

            $response = wp_remote_get( $api_endpoint, $args );
        }

        return $response['body'];
    }

    function enqueue_scripts(){
        $include_bootstrap_styles = get_option( 'ftf_alt_embed_tweet_include_bootstrap_styles', 'on' );
        $show_metrics = get_option( 'ftf_alt_embed_tweet_show_metrics', 'on' );

        $plugin_dir_url = plugin_dir_url(__FILE__);
        $plugin_dir_path = plugin_dir_path(__FILE__);

        $js_url = $plugin_dir_url . 'dist/js/scripts.min.js';
        $js_path = $plugin_dir_path . 'dist/js/scripts.min.js';

        $use_api = true;

        $twitter_api_consumer_key = get_option( 'ftf_alt_embed_tweet_twitter_api_consumer_key' );
        $twitter_api_consumer_secret = get_option( 'ftf_alt_embed_tweet_twitter_api_consumer_secret' );
        $twitter_api_oauth_access_token = get_option( 'ftf_alt_embed_tweet_twitter_api_oauth_access_token' );
        $twitter_api_oauth_access_token_secret = get_option( 'ftf_alt_embed_tweet_twitter_api_oauth_access_token_secret' );


        if ( empty( $twitter_api_consumer_key ) || empty( $twitter_api_consumer_secret ) || empty( $twitter_api_oauth_access_token ) || empty( $twitter_api_oauth_access_token_secret ) ){
            $use_api = false;
        }

        // $use_api = false;

        wp_register_script( 'ftf-ate-frontend-js', $js_url, array(), filemtime( $js_path ), true );
        wp_localize_script( 'ftf-ate-frontend-js', 'ftf_aet', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'config' => array(
                'show_metrics' => $show_metrics === 'on',
                'use_api' => $use_api
            )
        ) );

        wp_enqueue_script( 'ftf-ate-frontend-js' );

        if ( $include_bootstrap_styles === 'on' ){
            $css_url = $plugin_dir_url . 'dist/css/styles-bs.min.css';
            $css_path = $plugin_dir_path . 'dist/css/styles-bs.min.css';
        } else {
            $css_url = $plugin_dir_url . 'dist/css/styles.min.css';
            $css_path = $plugin_dir_path . 'dist/css/styles.min.css';
        }

        wp_enqueue_style( 'ftf-ate-frontend-styles', $css_url, array(), filemtime( $css_path ) );
    }

    function embed_tweet(){
        $twitter_api_consumer_key = get_option( 'ftf_alt_embed_tweet_twitter_api_consumer_key' );
        $twitter_api_consumer_secret = get_option( 'ftf_alt_embed_tweet_twitter_api_consumer_secret' );
        $twitter_api_oauth_access_token = get_option( 'ftf_alt_embed_tweet_twitter_api_oauth_access_token' );
        $twitter_api_oauth_access_token_secret = get_option( 'ftf_alt_embed_tweet_twitter_api_oauth_access_token_secret' );

        $include_bootstrap_styles = get_option( 'ftf_alt_embed_tweet_include_bootstrap_styles' );
        $show_metrics = get_option( 'ftf_alt_embed_tweet_show_metrics' );
        $cache_expiration = get_option( 'ftf_alt_embed_cache_expiration' );

        if ( empty( $cache_expiration ) ){
            $cache_expiration = 30;
        }

        $data = array();

        if ( !empty( $twitter_api_consumer_key ) && !empty( $twitter_api_consumer_secret ) && !empty( $twitter_api_oauth_access_token ) && !empty( $twitter_api_oauth_access_token_secret ) ){

            $settings = array(
                'consumer_key' => $twitter_api_consumer_key,
                'consumer_secret' => $twitter_api_consumer_secret,
                'oauth_access_token' => $twitter_api_oauth_access_token,
                'oauth_access_token_secret' => $twitter_api_oauth_access_token_secret
            );

            $tweet_ids = sanitize_text_field( $_POST[ 'tweet_ids' ] );
            $tweet_ids = explode( ',', $tweet_ids );

            if ( !empty( $tweet_ids ) ){
                foreach ( $tweet_ids as $index => $tweet_id ) {
                    if ( empty( $tweet_id ) ){
                        unset( $tweet_ids[$index] );
                    } else {
                        $cache_key = "ftf_alt_embed_tweet_data:" . $tweet_id;
                        $tweet_data = wp_cache_get( $cache_key );

                        if ( $tweet_data !== false ){
                            unset( $tweet_ids[$index] );
                            $data[] = $tweet_data;
                        }
                    }
                }

                $url = 'https://api.twitter.com/2/tweets';
                $request_method = 'GET';

                $post_fields = array(
                    'ids' => implode( ',', $tweet_ids ),
                    'expansions' => 'author_id,attachments.media_keys,referenced_tweets.id,attachments.poll_ids',
                    'tweet.fields' => 'attachments,entities,author_id,conversation_id,created_at,id,in_reply_to_user_id,lang,referenced_tweets,source,text,public_metrics',
                    'user.fields' => 'id,name,username,profile_image_url,verified',
                    'media.fields' => 'media_key,preview_image_url,type,url,width,height'
                );

                $response = self::call_twitter_api(  'tweets?' . str_replace( '%2C', ',', http_build_query( $post_fields ) ) );
                $response_array = json_decode( rtrim($response, "\0") );
                $tweet_data = array();

                foreach ( $response_array->data as $tweet ) {

                    $tweet->users = array();

                    foreach( $response_array->includes->users as $user ){
                        if ( $tweet->author_id === $user->id ){
                            $tweet->users[] = $user;
                        }

                    }
                    
                    $tweet->media = array();

                    if ( $tweet->attachments && $tweet->attachments->media_keys ){
                        foreach ( $tweet->attachments->media_keys as $media_key ) {
                            foreach( $response_array->includes->media as $media ){
                                if ( $media_key === $media->media_key ){
                                    $tweet->media[] = $media;
                                }
                            }
                        }

                    }

                    $tweet->polls = array();

                    if ( $tweet->attachments && $tweet->attachments->poll_ids ){
                        foreach ( $tweet->attachments->poll_ids as $poll_id ) {
                            foreach( $response_array->includes->polls as $poll ){
                                if ( $poll_id === $poll->id ){
                                    $tweet->polls[] = $poll;
                                }
                            }
                        }
                    }

                    $cache_key = "ftf_alt_embed_tweet_data:" . $tweet->id;
                    wp_cache_set( $cache_key, $tweet, '', ( $cache_expiration * MINUTE_IN_SECONDS ) );
                    $tweet_data[] = $tweet;
                }

                $data = array_merge( $data, $tweet_data );
            }
        }

        wp_send_json( $data );
    }

    function remove_twitter_script( $block_content, $block ) {
        if ( $block['blockName'] === 'core-embed/twitter' ) {
            $block_content = str_replace( '<script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>', '', $block_content );
            $block_content = str_replace( '<script async src=\"https:\/\/platform.twitter.com\/widgets.js\" charset=\"utf-8\"><\/script>', '', $block_content );
        }

        return $block_content;
    }

    function add_settings_page(){
        add_options_page(
            'Settings for the Tweet Embeds plugin',
            'Tweet Embeds',
            'manage_options',
            'ftf-alt-embed-tweet',
            array( $this, 'render_settings_page' )
        );
    }

    function settings_init(){
        register_setting( 'ftf_alt_embed_tweet', 'ftf_alt_embed_tweet_twitter_api_consumer_key', 'esc_attr' );
        register_setting( 'ftf_alt_embed_tweet', 'ftf_alt_embed_tweet_twitter_api_consumer_secret', 'esc_attr' );
        register_setting( 'ftf_alt_embed_tweet', 'ftf_alt_embed_tweet_twitter_api_oauth_access_token', 'esc_attr' );
        register_setting( 'ftf_alt_embed_tweet', 'ftf_alt_embed_tweet_twitter_api_oauth_access_token_secret', 'esc_attr' );
        register_setting( 'ftf_alt_embed_tweet', 'ftf_alt_embed_tweet_custom_styles', 'esc_attr' );
        register_setting( 'ftf_alt_embed_tweet', 'ftf_alt_embed_tweet_include_bootstrap_styles', 'esc_attr' );
        register_setting( 'ftf_alt_embed_tweet', 'ftf_alt_embed_tweet_show_metrics', 'esc_attr' );
        register_setting( 'ftf_alt_embed_tweet', 'ftf_alt_embed_cache_expiration', 'esc_attr' );

        add_settings_section(
            'ftf_alt_embed_tweet_settings', 
            __( '', 'wordpress' ), 
            array( $this, 'render_settings_form' ),
            'ftf_alt_embed_tweet'
        );
    }

    function render_settings_page(){ ?>
        <div class="wrap">
        <h1>Tweet Embeds</h1>

        <form action='options.php' method='post' >
            <?php
            settings_fields( 'ftf_alt_embed_tweet' );
            do_settings_sections( 'ftf_alt_embed_tweet' );
            submit_button();
            ?>
            </form>
        </div>
    <?php }

    function render_settings_form(){
        /* Twitter API keys */
        $twitter_api_consumer_key = get_option( 'ftf_alt_embed_tweet_twitter_api_consumer_key' );
        $twitter_api_consumer_secret = get_option( 'ftf_alt_embed_tweet_twitter_api_consumer_secret' );
        $twitter_api_oauth_access_token = get_option( 'ftf_alt_embed_tweet_twitter_api_oauth_access_token' );
        $twitter_api_oauth_access_token_secret = get_option( 'ftf_alt_embed_tweet_twitter_api_oauth_access_token_secret' );

        /* Customization */

        $include_bootstrap_styles = get_option( 'ftf_alt_embed_tweet_include_bootstrap_styles', 'on' );
        $show_metrics = get_option( 'ftf_alt_embed_tweet_show_metrics', 'on' );
        $custom_styles = get_option( 'ftf_alt_embed_tweet_custom_styles' );
        $cache_expiration = get_option( 'ftf_alt_embed_cache_expiration' );

        ?>

        <h3 id="about">About the plugin</h3>
        <p>Embed Tweets on your WordPress website without 3rd party scripts, improving your site's performance and protecting your visitors' privacy.</p>
        <p>Please reach out with any questions <a href="mailto:stefan@fourtonfish.com?subject=Tweet Embeds WordPress Plugin">via email</a> or <a href="https://twitter.com/fourtonfish">Twitter</a>.</p>
        
        <p>
            <a class="button" href="https://fourtonfish.com/project/tweet-embeds-wordpress-plugin/" target="_blank">Learn more</a>
            <a class="button" href="https://github.com/fourtonfish/tweet-embeds-wordpress-plugin" target="_blank">View source</a>
        </p>

        <h3 id="settings-twitter-api-keys">Twitter API keys</h3>
        <?php if ( empty( $twitter_api_consumer_key ) || empty( $twitter_api_consumer_secret ) || empty( $twitter_api_oauth_access_token ) || empty( $twitter_api_oauth_access_token_secret ) ){ ?>
            <p>To show the number of likes and retweets and include images and GIFs in Tweets, you need to sign up for a Twitter developer account and add your API keys below.</p>
            <!-- <p><a class="button" href="https://botwiki.org/tutorials/how-to-create-a-twitter-app/" target="_blank">See how</a></p> -->
            <p><a class="button" href="https://developer.twitter.com/en/apps" target="_blank">Open Twitter developer dashboard</a></p>
        <?php } else { ?>
            <p>Manage you API keys in the <a href="https://developer.twitter.com/en/apps" target="_blank">Twitter developer dashboard</a>.</p>
        <?php } ?>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="ftf-alt-embed-tweet-width-restriction">Your Twitter API keys</label>
                    </th>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ftf-alt-embed-tweet-twitter-api-consumer_key">Consumer Key</label>
                    </th>
                    <td>
                        <input id="ftf-alt-embed-tweet-twitter-api-consumer_key"
                        type="password"
                        name="ftf_alt_embed_tweet_twitter_api_consumer_key"
                        value="<?php echo $twitter_api_consumer_key; ?>"
                        placeholder="***************">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ftf-alt-embed-tweet-twitter-api-consumer_secret">Consumer Secret</label>
                    </th>
                    <td>
                        <input id="ftf-alt-embed-tweet-twitter-api-consumer_secret"
                        type="password"
                        name="ftf_alt_embed_tweet_twitter_api_consumer_secret"
                        value="<?php echo $twitter_api_consumer_secret; ?>"
                        placeholder="***************">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ftf-alt-embed-tweet-twitter-api-oauth_access_token">Access Token</label>
                    </th>
                    <td>
                        <input id="ftf-alt-embed-tweet-twitter-api-oauth_access_token"
                        type="password"
                        name="ftf_alt_embed_tweet_twitter_api_oauth_access_token"
                        value="<?php echo $twitter_api_oauth_access_token; ?>"
                        placeholder="***************">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ftf-alt-embed-tweet-twitter-api-oauth_access_token_secret">Access Token Secret</label>
                    </th>
                    <td>
                        <input id="ftf-alt-embed-tweet-twitter-api-oauth_access_token_secret"
                        type="password"
                        name="ftf_alt_embed_tweet_twitter_api_oauth_access_token_secret"
                        value="<?php echo $twitter_api_oauth_access_token_secret; ?>"
                        placeholder="***************">
                    </td>
                </tr>
            </tbody>
        </table>
        <h3 id="settings-customization">Customization</h3>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="ftf-alt-embed-tweet-cache_expiration">Cache Expiration (in minutes)</label>
                    </th>
                    <td>
                        <input id="ftf-alt-embed-tweet-cache_expiration"
                        type="number"
                        min="5"
                        name="ftf_alt_embed_cache_expiration"
                        value="<?php echo $cache_expiration; ?>"
                        placeholder="30">
                    </td>
                    <p class="description">
                        The Twitter API allows <a href="https://developer.twitter.com/en/docs/twitter-api/tweets/lookup/api-reference/get-tweets" target="_blank">900 requests per 15-minute window</a>. Based on your site's traffic and overall number of embedded Tweets you might want to increase how long the Twitter data should be cached to reduce the number of API calls. 
                    </p>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ftf-alt-show-metrics">Show number of likes and retweets</label>
                    </th>
                    <td>
                        <input type="checkbox" <?php checked( $show_metrics, 'on' ); ?> name="ftf_alt_embed_tweet_show_metrics" id="ftf-alt-show-metrics">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ftf-alt-include-bootstrap-styles">Load necessary Bootstrap styles</label>
                    </th>
                    <td>
                        <input type="checkbox" <?php checked( $include_bootstrap_styles, 'on' ); ?> name="ftf_alt_embed_tweet_include_bootstrap_styles" id="ftf-alt-include-bootstrap-styles">
                        <p class="description">
                            If you use <a href="https://getbootstrap.com/" target="_blank">Bootstrap (version 4)</a> on your site, you can uncheck this. Otherwise a slimmed-down version of the Bootstrap CSS library will be loaded and only applied to the embedded Tweets.
                        </p>
                    </td>
                </tr>
<!--
                <tr>
                    <th scope="row">
                        <label for="ftf-alt-embed-tweet-custom_styles">Additional CSS</label>
                    </th>
                    <td>
                        <textarea
                            id="ftf-alt-embed-tweet-custom_styles"
                            name="ftf_alt_embed_tweet_custom_styles"
                            rows="4"
                            cols="50"
                            style="font-family: monospace;"
                        ><?php echo $custom_styles; ?></textarea>
                        <p class="description">
                            Add additional CSS styles. <a href="https://jigsaw.w3.org/css-validator/#validate_by_input" target="_blank">Use the CSS validator</a> to make sure your CSS is valid.
                        </p>                        
                    </td>
                </tr>
-->
            </tbody>
        </table>
    <?php }    

    function settings_page_link( $links ){
        $url = esc_url( add_query_arg(
            'page',
            'ftf-alt-embed-tweet',
            get_admin_url() . 'admin.php'
        ) );
        $settings_link = "<a href='$url'>" . __( 'Settings' ) . '</a>';
        array_push(
            $links,
            $settings_link
        );
        return $links;
    }
}

$ftf_alt_embed_init = new FTF_Alt_Embed_Tweet();
