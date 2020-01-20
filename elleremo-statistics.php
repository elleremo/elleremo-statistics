<?php
/**
 * Plugin Name: elleremo-statistics
 * Description: Статистика просмотров постов
 * Version: 2.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Elleremo_Statistics' ) ) {

	/**
	 * Class Elleremo_Statistics
	 */
	final class Elleremo_Statistics {

		/**
		 * Хранит экземпляр класса
		 *
		 * @var $instance
		 */
		private static $instance;

		/**
		 * @var wpdb $wpdb
		 */
		private $wpdb;

		/**
		 * Включить/Выключить дебаг
		 */
		const DEBUG = false;

		/**
		 * Имя транизтного ключа для кеша
		 */
		const TRANSIENT = '_elleremo__statistics_post_';

		/**
		 * Неймспейс для REST API
		 */
		const REST_NAMESPACE = 'elleremo-statistics/v1.0';

		/**
		 * Вернуть единственный экземпляр класса
		 *
		 * @return Elleremo_Statistics
		 */
		public static function get_instance() {

			if ( is_null( self::$instance ) ) {
				self::$instance = new self;
			}

			return self::$instance;
		}

		/**
		 * Elleremo_Statistics constructor.
		 */
		public function __construct() {
			$this->setup();
			$this->includes();
			$this->hooks();
		}

		/**
		 * Пока пусто
		 */
		private function setup() {
			global $wpdb;

			$this->wpdb = $wpdb;
		}

		/**
		 * @return wpdb
		 */
		private function wpdb() {
			return $this->wpdb;
		}

		private function get_table() {
			return $this->wpdb()->prefix . 'statistics';
		}

		/**
		 * Подключение файлов
		 */
		private function includes() {

			// Подключим отладчик, если включен режим дебага.
			if ( true === self::DEBUG && file_exists( WP_PLUGIN_DIR . '/wp-php-console/vendor/autoload.php' ) ) {
				require_once WP_PLUGIN_DIR . '/wp-php-console/vendor/autoload.php';

				if ( ! class_exists( 'PC', false ) ) {
					PhpConsole\Helper::register();
				}
			}
		}

		/**
		 * Инициализация хуков
		 */
		private function hooks() {
			add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
			add_action( 'after_delete_post', array( $this, 'after_delete_post' ) );
			add_filter( 'manage_posts_columns', array( $this, 'add_viewed_column_in_posts' ) );
			add_action( 'manage_posts_custom_column', array( $this, 'add_viewed_column_content_in_posts' ), 10, 2 );
			add_filter( 'manage_edit-post_sortable_columns', array( $this, 'add_viewed_column_sortable_in_posts' ) );
			add_filter( 'posts_clauses', array( $this, 'orderby_viewed' ), 10, 2 );
			add_action( 'wp_head', array( $this, 'head' ) );
			add_action( 'elleremo_statistics_get_post_total', array( $this, 'the_post_total' ) );
		}

		/**
		 * Set sortable by viewed
		 *
		 * @param array    $pieces
		 * @param WP_Query $wp_query
		 *
		 * @return array
		 */
		public function orderby_viewed( array $pieces, WP_Query $wp_query ) {
			global $wpdb, $pagenow;

			if ( ! is_admin() ) {
				return $pieces;
			}

			// Сортировка по популярности.
			if ( 'edit.php' === $pagenow && 'viewed' === $wp_query->get( 'orderby' ) ) {
				$order = $wp_query->get( 'order' );

				$pieces['fields'] .= ', SUM(s.count) AS viewed';
				$pieces['join']   .= " LEFT JOIN {$wpdb->prefix}statistics AS s ON {$wpdb->posts}.ID=s.post_id";
				$pieces['groupby'] = "{$wpdb->posts}.ID HAVING viewed>0";
				$pieces['orderby'] = "viewed {$order}";

				return $pieces;
			}

			return $pieces;
		}

		/**
		 * Add column to sortable list.
		 *
		 * @param $columns
		 *
		 * @return mixed
		 */
		public function add_viewed_column_sortable_in_posts( $columns ) {
			$columns['viewed'] = 'viewed';

			return $columns;
		}

		/**
		 * Регистрация своих маршрутов в REST API
		 */
		public function register_rest_route() {
			register_rest_route(
				self::REST_NAMESPACE,
				'/increment',
				array(
					'methods'  => 'POST',
					'args'     => array(
						'post_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
					'callback' => array( $this, 'increment' ),
				)
			);
		}

		/**
		 * Увеличивает значение просмотров поста на еденицу
		 *
		 * @param WP_REST_Request $request
		 *
		 * @return false|int
		 */
		public function increment( \WP_REST_Request $request ) {

			$post_id = $request->get_param( 'post_id' );

			$result = $this->wpdb()->query(
				$this->wpdb()->prepare(
					"INSERT INTO {$this->get_table()}
							SET
								post_id = %d,
								date = CURRENT_DATE(),
								count = 1
							ON DUPLICATE KEY UPDATE
								count = count + 1
					",
					$post_id
				)
			); // WPCS: unprepared SQL OK

			return $result;
		}

		/**
		 * При удалении поста - стереть стату о нем
		 *
		 * @param int $post_id идентификатор поста.
		 */
		public function after_delete_post( $post_id ) {
			$this->wpdb->delete(
				$this->get_table(),
				array( 'post_id' => $post_id ),
				array( '%d' )
			); // db call ok; no-cache ok.
		}

		/**
		 * Регистрируем колонку с количеством просмотров постов
		 *
		 * @param array $defaults массив колонок.
		 *
		 * @return mixed
		 */
		public function add_viewed_column_in_posts( $defaults ) {
			$defaults['viewed'] = 'Просмотры';
			return $defaults;
		}

		/**
		 * Выводим количество просмотров для каждого поста в админке
		 *
		 * @param string $column_name название колонки.
		 * @param int    $post_id ID поста.
		 */
		public function add_viewed_column_content_in_posts( $column_name, $post_id ) {
			if ( 'viewed' === $column_name ) {
				$this->the_post_total( $post_id );
			}
		}

		/**
		 * Вывести отладку в консоль браузера
		 *
		 * @param $object
		 * @param string $tag
		 */
		public static function debug( $object, $tag = '' ) {

			if ( true === self::DEBUG ) {

				if ( empty( $tag ) ) {
					$tag = time();
				}

				PC::debug( $object, $tag );
			}
		}

		/**
		 * Получить общее число просмотров для поста
		 *
		 * @param int|null $post_id ID поста.
		 * @return int
		 */
		public function get_post_total( $post_id = null ) {

			if ( empty( $post_id ) ) {
				$_post = get_post();

				if ( $_post ) {
					$post_id = $_post->ID;
				}
			}

			$cached = get_transient( self::TRANSIENT . $post_id );
			if ( false !== $cached ) {
				return absint( $cached );
			}

			$total = $this->wpdb()->get_var(
				$this->wpdb->prepare(
					"SELECT SUM(`count`) AS cnt
						   FROM {$this->get_table()}
						   WHERE post_id = %d
						   GROUP BY post_id
					",
					$post_id
				)
			); // WPCS: unprepared SQL OK

			if ( $total < 1 ) {
				$total = 0;
			}

			set_transient( self::TRANSIENT . $post_id, $total, 3 * MINUTE_IN_SECONDS );

			return absint( $total );
		}

		/**
		 * Вывести количество просмотров
		 *
		 * @param int $post_id идентификатор поста
		 *
		 * @return int
		 */
		public function the_post_total( $post_id = null ) {
			echo $this->get_post_total( $post_id );
		}

		/**
		 * Добавить скрипт в футер через ajax для обхода кеша
		 */
		public function head() {
			/** @var WP_Post $post объект поста */
			global $post;
			if ( is_singular() && 'publish' === $post->post_status ) :
				$config = array(
					'post_id'  => $post->ID,
					'ajax_url' => get_rest_url( 0, self::REST_NAMESPACE . '/increment' ),
				); ?>

				<!-- Elleremo_Statistics -->
				<script>
					var elleremo_statistics = <?php echo json_encode( $config ); ?>;
					(function(){
						var js = document.createElement('script'); js.type = 'text/javascript'; js.async = true;
						js.src = '<?php echo esc_js( plugins_url( '/assets/js/views.js', __FILE__ ) ); ?>';
						var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(js, s);
					})();
				</script>
				<!-- /Elleremo_Statistics -->
				<?php
			endif;
		}

		/**
		 * Создать нужные таблицы в базе данных
		 */
		public function create_db() {

			$charset_collate = $this->wpdb()->get_charset_collate();

			$sql = "
				CREATE TABLE IF NOT EXISTS {$this->get_table()} (
				  id bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
				  post_id bigint(20) unsigned NOT NULL COMMENT 'ID поста',
				  date date NOT NULL COMMENT 'Дата',
				  count bigint(20) unsigned NOT NULL COMMENT 'Сумма за день',
				  PRIMARY KEY (id),
				  UNIQUE KEY `post_id_date` (`post_id`,`date`),
				  KEY idx_alpina2_statistics_post_id (post_id),
				  KEY idx_alpina2_statistics_date (date)
				) $charset_collate COMMENT='Статистика по страницам';
			";

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			dbDelta( $sql );
		}
	}

	/**
	 * Обертка над классом
	 *
	 * @return Elleremo_Statistics
	 */
	function elleremo_statistics() {
		return Elleremo_Statistics::get_instance();
	}

	/**
	 * Инициализация класса
	 */
    elleremo_statistics();
}

// eof;
