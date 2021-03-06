<?php  

// WP directory
$wordpress = '../wp/';

require_once $wordpress . 'wp-blog-header.php'; // get wordpress functions
require_once 'zooconvert.php'; // get the converter

class ZOOtoWP {

	private $start = '';

	private $log = array();

	private $converter = null;

	private $translations = array();

	public function __construct($url, $rename = array(), $lang = 'nb_NO', $folder = '') {

		$this->converter = new ZOOConvert(trailingslashit($url), $rename, $lang, $folder);

	}

	public function translate( $translations ) {
		$this->translations = $translations;
	}

	public function run( $post_type = '' ) {

		// convert the items
		$this->converter->execute();

		if( ! isset($this->converter->import['items'][$post_type]) ) {
			$this->log[] = '<strong>Post type does not exist in export</strong>';
			return;
		}
		else {
			$items = $this->converter->import['items'][$post_type];
			$categories = $this->converter->import['categories'][$post_type];
			$this->converter->import['items'] = array($post_type => $items);
			$this->converter->import['categories'] = array($post_type => $categories);;
		}

		$this->start = microtime(true);

		$this->log[] = '<strong>Starting import</strong>';

		// import images
		$this->images();

		// import categories
		$this->categories();

		// import posts
		$this->posts();

		$stop = microtime(true);

		$lapse = $stop - $this->start;

		$this->log[] = '<strong>Import finished in '. $lapse . ' seconds</strong>';

	}

	public function print_log() {
		// display log
		echo implode('<br>', $this->log);
	}

	public function categories() {

		$this->log[] = 'Starting category import';

		foreach( $this->converter->import['categories'] as $post_type => $items) {

			$taxonomy = 'category_'. $post_type;

			$this->log[] = '<strong>Importing categories for '. $post_type .'</strong>';

			if( !taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, $post_type );
				$this->log[] = 'Taxonomy does not exists. It has been temporarily created';
				$this->log[] = "<pre><code>register_taxonomy( '$taxonomy', '$post_type' );</code></pre>";
			}

			// first import parents
			foreach($items['parents'] as $slug => $languages) {
				$this->create_category($post_type, 0, $languages);
			}

			// then import children
			foreach($items['children'] as $slug => $languages) {
				$this->create_category($post_type, $slug, $languages);
			}

		}

		$this->log[] = 'Categories imported';
		
	}

	public function create_category($post_type, $par, $languages) {

		global $polylang;

		if( !is_object($polylang) ) {
			$this->log[] = 'Polylang missing. Please install.';
		} 

		$taxonomy = 'category_items';
		$terms = array();

		foreach( $languages as $language => $category ) {

			$this->log[] = 'Creating category: ' . $category['name'];

			$language = str_replace('-', '_', $language);

			$lang = $polylang->model->get_language($language);

			$parent = 0;
			$par = 0;
			if( $language === 'nb_NO' ) {
				$par = $this->translations[$post_type];
			}
			else {
				$par = $post_type;
			}

			if( $par !== 0 ) {
				$parent = get_term_by( 'slug', $par, $taxonomy );

				if( $parent ) {
					$parent = $parent->term_id;
				}
			}

			if( ! $term = term_exists( $category['slug'], $taxonomy ) ) {

				$description = substr( $category['description'], 0, 1 ) === '{' ? $category['byline'] : $category['description'];
				$term = wp_insert_term( 
					$category['name'], 
					$taxonomy, 
					array(
						'slug' 			=> $category['slug'],
						'parent'		=> $parent,
						'description'	=> $description
					)
				);
				$this->log[] = '<em>Category created</em>';
			}
			else {
				wp_update_term( $term['term_id'], 'category_items', array( 'parent' => $parent ) );
				$this->log[] = '<em>Category exists. Updated parent.</em>';
			}

			$option = $taxonomy . '_' . $term['term_id'] . '_';

			update_option( $option . 'image', $this->get_image($category['image']) );
			update_option( $option . 'byline', $category['byline'] );

			$code = $lang->slug;

			$terms[$code] = $term;

			$polylang->model->set_term_language( $term['term_id'], $code );

		}

		if( isset($terms['en']) )
			$polylang->model->save_translations('term', $terms['nb']['term_id'], array('en' => $terms['en']['term_id']));



	}

	public function posts() {

		$this->log[] = 'Starting item import';

		foreach( $this->converter->import['items'] as $post_type => $items) {

			$this->log[] = '<strong>Importing posts for '. $post_type .'</strong>';

			// if( ! post_type_exists( $post_type ) ) {
			// 	register_post_type( $post_type );
			// 	$this->log[] = 'Post type does not exists. It has been temporarily created';
			// 	$this->log[] = "<pre><code>register_post_type( '$post_type' );</code></pre>";
			// }

			foreach( $items as $item ) {
				$post = $this->post($item['post'], $item['fields']);
			}

		}

	}

	public function post_exists( $title, $post_type ) {

		global $wpdb;

		$post_title = wp_unslash( sanitize_post_field( 'post_title', $title, 0, 'db' ) );

		$args = array();
		$query = "SELECT ID FROM $wpdb->posts WHERE 1=1";
		$query .= ' AND post_title = %s';
		$query .= ' AND post_type = %s';
		$args[] = $post_title;
		$args[] = 'items';

		if ( !empty ( $args ) )
			return (int) $wpdb->get_var( $wpdb->prepare($query, $args) );

	}

	public function post_categories( $categories, $post_type, $lang = 'nb' ) {

		global $polylang;

		$cats = array();

		if( !$categories )
			return $cats;

		foreach($categories as $category) {
			$term = get_term_by( 'slug', $category, 'category_items' );

			if( $term && $lang !== 'nb' ) {
				$translation = $polylang->model->get_translation('term', $term->term_id, $lang);
				if($translation)
					$term = get_term_by( 'id', $translation, 'category_items');
			}
			if($term) 
				$cats[] = $term->term_id;
		}

		return $cats;

	}

	public function post( $item, $fields ) {

		global $polylang;

		require_once ABSPATH . 'wp-admin/includes/post.php';

		$this->log[] = 'Importing post: '.$item['post_title'].'';

		if( !$post = $this->post_exists( $item['post_title'], 'items' ) ) {

			$first = $item;
			$second = $item;

			$first['tax_input'][ 'category_'. $item['post_type'] ][] = $this->translations[$item['post_type']];
			$first['tax_input']['category_items'] = $this->post_categories($first['tax_input']['category_'. $item['post_type']], 'category_items');
			unset($first['tax_input']['category_'. $item['post_type']]);
			$first['post_type'] = 'items';


			$second['post_name'] = $second['post_name'] . '-en';
			$second['tax_input'][ 'category_'. $item['post_type'] ][] = $item['post_type'];
			$second['tax_input']['category_items'] = $this->post_categories($second['tax_input']['category_'. $item['post_type']], 'category_items', 'en');
			unset($second['tax_input']['category_'. $item['post_type']]);
			$second['post_type'] = 'items';
			
			$post_no = wp_insert_post( $first, true );
			$post_en = wp_insert_post( $second, true );

			$polylang->model->set_post_language($post_no, 'nb');
			$polylang->model->set_post_language($post_en, 'en');

			$polylang->model->save_translations('post', $post_no, array('en' => $post_en));

			$this->fields($post_no, $fields);
			$this->fields($post_en, $fields);

		}
		else {

			$first = $item;
			$second = $item;

			$post_id = $post;
			$first['tax_input'][ 'category_'. $item['post_type'] ][] = $this->translations[$item['post_type']];
			wp_set_object_terms($post_id, $this->post_categories($first['tax_input']['category_'. $item['post_type']], 'category_items'), 'category_items', true);

			$translation = pll_get_post($post_id, 'en');
			$second['tax_input'][ 'category_'. $item['post_type'] ][] = $item['post_type'];
			wp_set_object_terms($translation, $this->post_categories($second['tax_input']['category_'. $item['post_type']], 'category_items', 'en'), 'category_items', true);

			$this->log[] = '<em>Post already exists. Updated categories.</em>';
		}

		return $post;

	}

	public function fields($post_id, $fields) {

		$this->log[] = 'Importing fields for: '.$post_id.'';

		foreach( $fields as $field ) {
			$key 	= $field['key'];
			$value 	= $field['value'];
			if( $field['type'] === 'image' ) {
				if( $val = $this->get_image($value) ) {
					$value = $val;
				}
			}
			if( $field['type'] === 'gallery' ) {
				$value = $this->gallery($value);
			}
			update_post_meta( $post_id, $key, $value );
		}

	}

	public function images() {

		$this->log[] = 'Importing images';

		foreach($this->converter->import['images'] as $key => $image) {

			$url = $this->converter->url . str_replace(' ', '%20', $image);

			$image = $this->get_image($url);
			
			if( ! $image ) {
				$image = $this->image($url);
				if( is_wp_error($image) )
					$this->log[] = '<em>Image import failed.</em>';
			}
		}

		$this->log[] = 'Images imported';

	}

	public function get_image( $url ) {

		$name = sanitize_file_name(basename($url));

		global $wpdb;

		$query = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE '%/{$name}' LIMIT 1";

		return $wpdb->get_var($query);

	}

	public function image( $url ) {


		$this->log[] = 'Importing image: '.$url.'';

		require_once(ABSPATH . 'wp-admin/includes/media.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');

	    $tmp = download_url( $url );
	    $file_array = array(
	        'name' => basename( $url ),
	        'tmp_name' => $tmp
	    );

	    // Check for download errors
	    if ( is_wp_error( $tmp ) ) {
	        @unlink( $file_array[ 'tmp_name' ] );
	        return $tmp;
	    }

	    $id = media_handle_sideload( $file_array, 0 );

	    // Check for handle sideload errors.
	    if ( is_wp_error( $id ) ) {
	        @unlink( $file_array['tmp_name'] );
	        return $id;
	    }

	    return $id;
	}

	public function gallery( $folder = '' ) {

		$gallery_dir = dirname(__FILE__) . '/images/images' . $folder;
		$gallery_url = trailingslashit( home_url() . '/test/images/images' . $folder );

		$files = trailingslashit( $gallery_dir ) . '*.{jpg,png,gif}';

		$gallery = array();

		foreach( glob($files, GLOB_BRACE) as $file ) {


			$image = basename($file);
			if( $image !== 'index.html' ) {

				$url = $gallery_url . str_replace(' ', '%20', $image);

				$image = $this->get_image($url);

				if( ! $image ) {
					$image = $this->image($url);
				}

				$gallery[] = $image;

			}

		}

		return $gallery;


	}

}

$zoo = new ZOOtoWP('http://visitandalsnes.com', array(
	'full_beskrivelse'		=> 'description',
	'full_description'		=> 'description_en',
	'kort_beskrivelse'		=> 'excerpt',
	'short_description'		=> 'excerpt_en',
	'miniatyrkart'			=> 'map',
	'minature_map'			=> 'map_en',
	'bildegalleri'			=> 'gallery',
	'gps_koordinater'		=> 'coordinates',
	'gps_coordinates'		=> 'coordinates_en',
	'teaser_image'			=> 'featured',
	'image'					=> 'featured_en',
	'kontaktinformasjon'	=> 'contact',
	'contact_information'	=> 'contact_en',
	'fasiliteter'			=> 'facilities',
	'facilities'			=> 'facilities_en',
	'hyrekolonne'			=> 'right',
	'right_column'			=> 'right_en'
));

$zoo->translate(array(
	'accommodation' 	=> 'overnatting',
	'activities' 		=> 'aktiviteter',
	'shopping' 			=> 'handel',
	'travel' 			=> 'reise',
	'gems' 				=> 'perler',
	'eating' 			=> 'spise',
	'winter' 			=> 'vinter',
));

$zoo->run('winter');

// print the log
$zoo->print_log();

?>