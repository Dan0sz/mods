<?php
/**
 * @package Daan\Mods
 * @author  Daan van den Bergh
 * @url     https://daan.dev
 * @license MIT
 */

namespace Daan\Mods;

/**
 * Lets customers delete their own account (GDPR's right to erasure), with the Delete Account block (e.g. below the
 * profile editor on the Account page). Its heading, introduction and texts are edited in the block editor.
 *
 * Uses WordPress' personal data erasers, which Easy Digital Downloads and its add-ons hook into:
 * - The customer is anonymized and their addresses and email logs are removed.
 * - Orders are anonymized (or deleted), depending on Downloads > Settings > Policies > Privacy > Order Statuses.
 *   What was bought, when, for how much and in which country is kept, for the tax administration.
 * - Reviews, comments and AffiliateWP's customer records are anonymized.
 *
 * Before that, their subscriptions are cancelled (at the gateway, so they're not charged again). EDD Recurring only does
 * that when a customer is anonymized in wp-admin. If that fails, the account isn't deleted.
 *
 * And then:
 * - License keys are detached from the account, so they keep working until they expire, and can still be renewed with
 *   the license key at checkout.
 * - The contact is deleted from Brevo.
 * - The WordPress user is deleted, and I'm notified by email (e.g. for invoices in Moneybird, which are kept).
 *
 * Only available to customers (see self::CUSTOMER_ROLES), not to staff or affiliates: those ask me.
 */
class DeleteAccount {
	/**
	 * The form's action.
	 */
	const ACTION = 'daan_delete_account';

	/**
	 * Added to the redirect after the account was deleted.
	 */
	const DELETED = 'account-deleted';

	/**
	 * Added to the redirect if a subscription couldn't be cancelled.
	 */
	const FAILED = 'account-not-deleted';

	/**
	 * The block's ID, e.g. to return to it after a failed attempt.
	 */
	const ID = 'daan-delete-account';

	/**
	 * Replaced with the site's name in the block's texts.
	 */
	const SITE_NAME = '{site_name}';

	/**
	 * Only users with no other roles than these can delete their own account. Staff (e.g. shop workers) ask me.
	 */
	const CUSTOMER_ROLES = [ 'subscriber', 'edd_subscriber', 'customer' ];

	/**
	 * Brevo for EDD's API key, in EDD's settings.
	 */
	const BREVO_API_KEY = 'edd_brevo_api_key';

	/**
	 * Build class.
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register_block' ] );
		add_action( 'template_redirect', [ $this, 'maybe_delete' ] );
	}

	/**
	 * @return void
	 */
	public function register_block() {
		register_block_type( dirname( __DIR__ ) . '/blocks/delete-account', [ 'render_callback' => [ $this, 'render' ] ] );
	}

	/**
	 * The block: its heading and introduction (inner blocks), what deleting the account does, and the form to do it.
	 *
	 * @param array  $attributes The texts edited in the block editor.
	 * @param string $content    The inner blocks.
	 *
	 * @return string
	 */
	public function render( $attributes, $content ) {
		$user = wp_get_current_user();

		// After deleting their account, the visitor is logged out and returns to this page.
		if ( ! $user->exists() ) {
			return isset( $_GET[ self::DELETED ] ) ? $this->wrap( '<div class="edd-alert edd-alert-success"><p>' . esc_html__( 'Your account has been deleted.', 'daan-mods' ) . '</p></div>' ) : '';
		}

		if ( ! $this->is_customer( $user ) ) {
			return '';
		}

		$blocked = $this->get_blocked_reason( $user );

		if ( isset( $_GET[ self::FAILED ] ) ) {
			$blocked = __( 'Something went wrong while cancelling your subscription, so your account wasn\'t deleted. I\'ve been notified.', 'daan-mods' );
		}

		ob_start();

		if ( $blocked ) :
			?>
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: 1: why the account can't be deleted, 2: contact page URL. */
						__( '%1$s To delete your account, please <a href="%2$s">contact me</a>.', 'daan-mods' ),
						esc_html( $blocked ),
						esc_url( home_url( '/contact/' ) )
					),
					[ 'a' => [ 'href' => [] ] ]
				);
				?>
			</p>
		<?php else : ?>
			<ul>
				<?php foreach ( $this->get_consequences( $user, $attributes ) as $consequence ) : ?>
					<li><?php echo wp_kses_post( $consequence ); ?></li>
				<?php endforeach; ?>
			</ul>
			<form method="post" action="<?php echo esc_url( edd_get_current_page_url() ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<p>
					<label>
						<input type="checkbox" name="daan_delete_account_confirm" value="1" required>
						<?php echo wp_kses_post( $attributes[ 'confirmLabel' ] ?? '' ); ?>
					</label>
				</p>
				<p>
					<button type="submit" class="button daan-delete-account-button"><?php echo wp_kses_post( $attributes[ 'buttonText' ] ?? '' ); ?></button>
				</p>
			</form>
			<?php
		endif;

		return $this->wrap( str_replace( self::SITE_NAME, esc_html( get_bloginfo( 'name' ) ), $content . ob_get_clean() ) );
	}

	/**
	 * The block's wrapper, with its border, colors and spacing.
	 *
	 * @param string $html
	 *
	 * @return string
	 */
	private function wrap( $html ) {
		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes( [ 'id' => self::ID ] ), $html );
	}

	/**
	 * @param \WP_User $user
	 *
	 * @return bool Whether the user is only a customer, and not staff.
	 */
	private function is_customer( $user ) {
		return $user->roles && ! array_diff( $user->roles, self::CUSTOMER_ROLES ) && ! is_super_admin( $user->ID );
	}

	/**
	 * Why the account can't be deleted by the customer.
	 *
	 * @param \WP_User $user
	 *
	 * @return string Empty if it can.
	 */
	private function get_blocked_reason( $user ) {
		// Their referrals and (unpaid) earnings need my attention.
		if ( function_exists( 'affwp_get_affiliate_id' ) && affwp_get_affiliate_id( $user->ID ) ) {
			return __( 'Because you\'re an affiliate, your account can\'t be deleted here.', 'daan-mods' );
		}

		$customer = $this->get_customer( $user );

		if ( ! $customer || ! function_exists( '_edd_privacy_get_payment_action' ) ) {
			return '';
		}

		// EDD doesn't anonymize a customer with orders it may not change (e.g. a payment that's still being processed).
		foreach ( edd_get_orders( [ 'customer_id' => $customer->id, 'number' => 9999 ] ) as $order ) {
			if ( _edd_privacy_get_payment_action( $order ) === 'none' ) {
				return __( 'Your account can\'t be deleted automatically, because of one of your orders.', 'daan-mods' );
			}
		}

		return '';
	}

	/**
	 * What deleting this account does: the block's texts which apply to this customer.
	 *
	 * @param \WP_User $user
	 * @param array    $attributes
	 *
	 * @return string[]
	 */
	private function get_consequences( $user, $attributes ) {
		$texts = [ 'accountText', 'ordersText' ];

		if ( $this->get_active_subscriptions( $user ) ) {
			$texts[] = 'subscriptionsText';
		}

		if ( $this->get_licenses( $user ) ) {
			$texts[] = 'licensesText';
		}

		$texts[] = 'newsletterText';

		return array_filter( array_map( fn( $text ) => $attributes[ $text ] ?? '', $texts ) );
	}

	/**
	 * Deletes the account when its form was sent.
	 *
	 * @return void
	 */
	public function maybe_delete() {
		if ( ( $_POST[ 'action' ] ?? '' ) !== self::ACTION || ! is_user_logged_in() ) {
			return;
		}

		check_admin_referer( self::ACTION );

		$user = wp_get_current_user();

		if ( ! $this->is_customer( $user ) || $this->get_blocked_reason( $user ) || empty( $_POST[ 'daan_delete_account_confirm' ] ) ) {
			wp_safe_redirect( edd_get_current_page_url() );
			exit;
		}

		$customer      = $this->get_customer( $user );
		$emails        = array_unique( array_filter( [ $user->user_email, $customer->email ?? '' ] ) );
		$subscriptions = $this->cancel_subscriptions( $user );

		// Don't delete the account of someone who may still be charged.
		if ( $subscriptions[ 'failed' ] ) {
			$this->notify_failed( $user, $emails, $subscriptions[ 'failed' ] );

			wp_safe_redirect( add_query_arg( self::FAILED, 1, edd_get_current_page_url() ) . '#' . self::ID );
			exit;
		}

		$licenses = $this->detach_licenses( $user );
		$messages = [];
		$failed   = [];

		foreach ( $emails as $email ) {
			$erased   = $this->erase_personal_data( $email );
			$messages = array_merge( $messages, $erased[ 'messages' ], $this->delete_brevo_contact( $email ) );
			$failed   = array_merge( $failed, $erased[ 'failed' ] );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		wp_logout();
		wp_delete_user( $user->ID );

		// The account is deleted anyway: by now, its subscriptions are cancelled and its customer may be anonymized.
		$this->notify( $user, $customer, $emails, $subscriptions[ 'cancelled' ], $licenses, $messages, $failed );

		wp_safe_redirect( add_query_arg( self::DELETED, 1, get_permalink() ?: home_url( '/account/' ) ) );
		exit;
	}

	/**
	 * Cancels active subscriptions at their gateway, like EDD Recurring does when a customer is anonymized in wp-admin.
	 *
	 * @param \WP_User $user
	 *
	 * @return array{cancelled: int, failed: int[]} The number of cancelled subscriptions, and the IDs of those that
	 *                                             couldn't be cancelled.
	 */
	private function cancel_subscriptions( $user ) {
		$result = [ 'cancelled' => 0, 'failed' => [] ];

		foreach ( $this->get_active_subscriptions( $user ) as $subscription ) {
			if ( $subscription->can_cancel() ) {
				$subscription->cancel();
			}

			// The gateway may have refused, e.g. while it's unreachable.
			$subscription = new \EDD_Subscription( $subscription->id );

			if ( $subscription->status !== 'cancelled' ) {
				$result[ 'failed' ][] = (int) $subscription->id;

				continue;
			}

			$subscription->add_note( __( 'Subscription cancelled, because the customer deleted their account.', 'daan-mods' ) );

			$result[ 'cancelled' ] ++;
		}

		return $result;
	}

	/**
	 * Runs all personal data erasers (Tools > Erase Personal Data) for an email address.
	 *
	 * @param string $email
	 *
	 * @return array{messages: string[], failed: string[]} Their messages (e.g. what was kept and why), and the erasers
	 *                                                    which failed or didn't finish, so personal data may remain.
	 */
	private function erase_personal_data( $email ) {
		$messages = [];
		$failed   = [];

		foreach ( apply_filters( 'wp_privacy_personal_data_erasers', [] ) as $eraser ) {
			if ( ! is_callable( $eraser[ 'callback' ] ?? null ) ) {
				continue;
			}

			$name = $eraser[ 'eraser_friendly_name' ] ?? '';
			$done = false;

			// Erasers work in pages, until they're done.
			for ( $page = 1; $page <= 100; $page ++ ) {
				$response = call_user_func( $eraser[ 'callback' ], $email, $page );

				if ( ! is_array( $response ) || ! is_array( $response[ 'messages' ] ?? [] ) ) {
					$failed[] = $name . ': ' . ( is_wp_error( $response ) ? $response->get_error_message() : 'invalid response' );

					continue 2;
				}

				foreach ( $response[ 'messages' ] ?? [] as $message ) {
					$messages[] = $name . ': ' . $message;
				}

				if ( ! empty( $response[ 'done' ] ) ) {
					$done = true;

					break;
				}
			}

			if ( ! $done ) {
				$failed[] = $name . ': not finished after 100 pages';
			}
		}

		return [ 'messages' => $messages, 'failed' => $failed ];
	}

	/**
	 * Licenses stay valid, but no longer belong to the deleted user: otherwise they can't be renewed by license key.
	 *
	 * @param \WP_User $user
	 *
	 * @return string[] The detached license keys.
	 */
	private function detach_licenses( $user ) {
		$keys = [];

		foreach ( $this->get_licenses( $user ) as $license ) {
			$license->update( [ 'user_id' => 0 ] );

			$keys[] = $license->key;
		}

		return $keys;
	}

	/**
	 * @param string $email
	 *
	 * @return string[] What went wrong, if anything.
	 */
	private function delete_brevo_contact( $email ) {
		$api_key = function_exists( 'edd_get_option' ) ? edd_get_option( self::BREVO_API_KEY ) : '';

		if ( ! $api_key ) {
			return [];
		}

		$response = wp_remote_request(
			'https://api.brevo.com/v3/contacts/' . rawurlencode( $email ),
			[
				'method'  => 'DELETE',
				'timeout' => 10,
				'headers' => [ 'api-key' => $api_key, 'accept' => 'application/json' ],
			]
		);
		$code     = wp_remote_retrieve_response_code( $response );

		// 404: they weren't a contact.
		if ( in_array( $code, [ 204, 404 ], true ) ) {
			return [];
		}

		return [
			sprintf(
				'Brevo: contact %s not deleted (%s).',
				$email,
				is_wp_error( $response ) ? $response->get_error_message() : $code . ' ' . wp_remote_retrieve_body( $response )
			),
		];
	}

	/**
	 * Lets me know, e.g. for what's kept elsewhere (Moneybird, Help Scout) or what couldn't be erased.
	 *
	 * @param \WP_User           $user
	 * @param \EDD_Customer|null $customer
	 * @param string[]           $emails
	 * @param int                $subscriptions
	 * @param string[]           $licenses
	 * @param string[]           $messages
	 * @param string[]           $failed   Erasers which failed: their personal data may remain.
	 *
	 * @return void
	 */
	private function notify( $user, $customer, $emails, $subscriptions, $licenses, $messages, $failed ) {
		$lines = [
			sprintf( 'A customer deleted their account on %s.', home_url() ),
			'',
			'Email: ' . implode( ', ', $emails ),
			'Name: ' . $user->display_name,
			'User ID: ' . $user->ID,
			'Customer ID: ' . ( $customer->id ?? '-' ),
			'Subscriptions cancelled: ' . $subscriptions,
			'License keys detached: ' . ( $licenses ? implode( ', ', $licenses ) : '-' ),
		];

		if ( $failed ) {
			$lines[] = '';
			$lines[] = 'These erasers FAILED, so personal data may remain (Tools > Erase Personal Data):';

			foreach ( $failed as $failure ) {
				$lines[] = '- ' . $failure;
			}
		}

		if ( $messages ) {
			$lines[] = '';
			$lines[] = 'Erasers:';

			foreach ( $messages as $message ) {
				$lines[] = '- ' . $message;
			}
		}

		$lines[] = '';
		$lines[] = 'Invoices in Moneybird and conversations in Help Scout are kept.';

		$this->mail_admin( $failed ? 'Account deleted, but personal data may remain' : 'Account deleted', $lines );
	}

	/**
	 * Lets me know an account wasn't deleted, because a subscription couldn't be cancelled.
	 *
	 * @param \WP_User $user
	 * @param string[] $emails
	 * @param int[]    $subscription_ids
	 *
	 * @return void
	 */
	private function notify_failed( $user, $emails, $subscription_ids ) {
		$lines = [
			sprintf( 'A customer tried to delete their account on %s, but it wasn\'t deleted: these subscriptions couldn\'t be cancelled.', home_url() ),
			'',
			'Email: ' . implode( ', ', $emails ),
			'User ID: ' . $user->ID,
			'Subscription IDs: ' . implode( ', ', $subscription_ids ),
		];

		$this->mail_admin( 'Account not deleted', $lines );
	}

	/**
	 * The account is gone by the time I'm notified, so if the email can't be sent, the report goes to PHP's error log.
	 *
	 * @param string   $subject
	 * @param string[] $lines
	 *
	 * @return void
	 */
	private function mail_admin( $subject, $lines ) {
		$subject = sprintf( '[%s] %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $subject );
		$message = implode( "\n", $lines );

		if ( ! wp_mail( get_option( 'admin_email' ), $subject, $message ) ) {
			error_log( 'Daan Mods: couldn\'t email this report. ' . $subject . "\n" . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * @param \WP_User $user
	 *
	 * @return \EDD_Customer|null
	 */
	private function get_customer( $user ) {
		if ( ! function_exists( 'edd_get_customer_by' ) ) {
			return null;
		}

		return edd_get_customer_by( 'user_id', $user->ID ) ?: null;
	}

	/**
	 * @param \WP_User $user
	 *
	 * @return array
	 */
	private function get_active_subscriptions( $user ) {
		$customer = $this->get_customer( $user );

		if ( ! $customer || ! class_exists( '\EDD_Recurring_Subscriber' ) ) {
			return [];
		}

		$subscriptions = ( new \EDD_Recurring_Subscriber( $customer->id ) )->get_subscriptions( 0, [ 'active', 'trialling' ] );

		return is_array( $subscriptions ) ? $subscriptions : [];
	}

	/**
	 * @param \WP_User $user
	 *
	 * @return \EDD_SL_License[]
	 */
	private function get_licenses( $user ) {
		if ( ! function_exists( 'edd_software_licensing' ) ) {
			return [];
		}

		return (array) edd_software_licensing()->licenses_db->get_licenses( [ 'user_id' => $user->ID, 'number' => - 1 ] );
	}
}
