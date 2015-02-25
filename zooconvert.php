<?php  

class ZOOConvert {

	/**
	 * The folder
	 *
	 * @var string
	 **/
	private $folder = '';

	/**
	 * The URL to the new site
	 *
	 * @var string
	 **/
	public $url = '';

	/**
	 * The files
	 *
	 * @var string
	 **/
	private $files = array();

	/**
	 * The default language
	 *
	 * @var string
	 **/
	private $lang = '';

	/**
	 * Decoded json strings
	 *
	 * @var string
	 **/
	private $parsed = array();

	/**
	 * Completed import array
	 *
	 * @var string
	 **/
	public $import = array();

	/**
	 * Rename field names
	 *
	 * @var string
	 **/
	private $rename = array();

	/**
	 * Rename field names
	 *
	 * @var string
	 **/
	private $translations = array('en_GB');

	/**
	 * Creates the instance
	 *
	 * @return void
	 **/
	public function __construct($url, $rename = array(), $lang = 'nb_NO', $folder = '') {

		if( !$folder )
			$folder = dirname(__FILE__). '/zoo/';

		$this->folder 	= $folder;
		$this->url 		= $url;
		$this->lang 	= $lang;
		$this->rename 	= $rename;

	}

	public function execute() {

		// locate all json files
		$this->locate();

		// parse them to php arrays
		$this->parse();

		// walk through categories
		$this->categories();

		// walk through items
		$this->items();
		
	}

	/**
	 * Looks for .json files in a folder
	 *
	 * @return void
	 **/
	private function locate() {

		$filenames = $this->folder . '*.json';

		foreach( glob($filenames) as $filename ) {
			$this->files[] = $filename;
		}

	}

	/**
	 * Parses a json-file
	 * 
	 * @return void
	 */
	private function parse() {

		if( $this->files ) {

			foreach($this->files as $file) {

				$key = str_replace('.json', '', basename($file));

				$contents = file_get_contents($file);
				$this->parsed[$key] = json_decode($contents);

			}

		}

	}

	/**
	 * Walks through categories
	 * 
	 * @return void
	 */
	private function categories() {

		if( $this->parsed ) {

			$categories = array('parents' => array(), 'children' => array());

			foreach($this->parsed as $post_type => $content) {
				
				foreach( $content->categories as $slug => $contained ) {

					if( isset($contained->parent) ) {

						$parent 		= $contained->parent;

						if( $contained->parent === '_root' ) {
							$parent 	= 0;
						}
						
						$name 			= trim( $contained->name );
						$desc 			= trim( $contained->description );
						$sub_headline 	= trim( isset($contained->content->sub_headline) ? $contained->content->sub_headline : '' );
						$image 			= trim( $contained->content->image );

						$key = $parent ? 'children' : 'parents';
						
						$categories[$key][$slug][$this->lang] = array(
							'name' 			=> $name,
							'slug' 			=> $slug,
							'description'	=> $desc,
							'image'			=> $image,
							'byline'		=> $sub_headline,
						);

						if( $contained->content->image )
							$this->import['images'][self::slug($image, '_')] = $image;

						if( isset($contained->content->name_translation) ) {
							foreach($this->translations as $language) {

								$lang 			= str_replace('_', '-', $language);
								$content 		= $contained->content;

								$name 			= trim( isset($content->name_translation->$lang) ? $content->name_translation->$lang : '' );
								$desc 			= trim( isset($content->desc_translation->$lang) ? $content->desc_translation->$lang : '' );
								$sub_headline 	= trim( isset($content->sub_headline_translation->$lang) ? $content->sub_headline_translation->$lang : '' );

								if($name) {
									$categories[$key][$slug][$language] = array(
										'name' 			=> $name,
										'slug' 			=> self::slug($name),
										'description'	=> $desc,
										'image'			=> $image,
										'byline'		=> $sub_headline
									);
								}

							} // end loop

						} // end if

					} // end if

				} // end second loop

				$this->import['categories'][$post_type] = $categories;

			} // end first loop

		} // end if

	}

	/**
	 * Walks through items
	 * 
	 * @return void
	 */
	private function items() {

		if( $this->parsed ) {

			$images = array();
			$galleries = array();

			foreach($this->parsed as $post_type => $content) {

				$items = array();

				foreach( $content->items as $slug => $item ) {

					$post = array(
						'post_title'		=> $item->name,
						'post_name'			=> $slug,
						'post_status'		=> $item->state !== '0' ? 'publish' : 'draft',
						'post_author'		=> 1,
						'post_content'		=> '',
						'post_date'			=> $item->created,
						'post_date_gmt'		=> $item->created,
						'post_type'			=> $post_type,
						'post_modified'		=> $item->modified,
						'post_modified_gmt'	=> $item->modified,
						'tax_input' 		=> isset($item->categories) ? array( 'category_'. $post_type  => (array)$item->categories ) : null,
					);

					$fields = array();

					foreach( $item->elements as $element ) {
						$key = self::slug($element->name, '_');

						if( isset($this->rename[$key])) {
							$key = $this->rename[$key];
						}
						$data = json_decode(json_encode($element->data), true);

						$value = false;
						switch( $element->type ) {
							case 'textarea':
								$data = array_pop($data);
								$value = $data['value'];
								break;

							case 'date':
								$data = array_pop($data);

								if( $data ) {
									$value = $data['value'];
								}
								break;

							case 'gallery':
								$value = $data['value'];
								$this->import['galleries'][self::slug($value, '_')] = $value;
								break;

							case 'googlemaps':
								if($data['location']) {
									$location = explode(',', $data['location']);
									$location = array_map('trim', $location);
									if( count($location) !== 2 ) {
										$location = array_pop($location);
										$location = explode(' ', $data['location']);
										$location = array_map('trim', $location);
									}
									$value = $location;
								}
								break;

							case 'image':
								if($data['file']) {
									$value = $data['file'];
									$this->import['images'][self::slug($value, '_')] = $value;
								}
								break;

							default:
								break;

						}

						if( $value ) {
							$fields[] = array(
								'key'	=> $key,
								'value'	=> $value,
								'type' 	=> $element->type
							);
						}
					}
					
					$items[] = array('post' => $post, 'fields' => $fields);

				}

				$this->import['items'][$post_type] = $items;

			} // end first loop

		} // end if

	}

	static public function slug($text, $char = '-') { 
		// replace non letter or digits by -
		$text = preg_replace('~[^\\pL\d]+~u', $char, $text);

		// trim
		$text = trim($text, $char);

		// transliterate
		$text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

		// lowercase
		$text = strtolower($text);

		// remove unwanted characters
		$text = preg_replace('~[^-\w]+~', '', $text);

		if (empty($text))
		{
			return 'n-a';
		}

		return $text;
	}

}

?>