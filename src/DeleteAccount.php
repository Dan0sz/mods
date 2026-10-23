<?php
/**
 * @package Daan\Mods
 * @author  Daan van den Bergh
 * @url     https://daan.dev
 * @license MIT
 */

namespace Daan\Mods;

/**
 * Lets customers delete their own account (GDPR's right to erasure), below the profile editor on the Account page.
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
 * Not available to affiliates and staff: those ask me.
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
	 * Brevo for EDD's API key, in EDD's settings.
	 */
	const BREVO_API_KEY = 'edd_brevo_api_key';

	/**
	 * Build class.
	 */
	public function __construct() {
		add_action( 'edd_profile_editor_after', [ $this, 'render' ] );
		add_action( 'edd_profile_editor_logged_out', [ $this, 'render_deleted' ] );
		add_action( 'template_redirect', [ $this, 'maybe_delete' ] );
	}

	/**
	 * Below the profile editor: what deleting the account does, and the form to do it.
	 *
	 * @return void
	 */
	public function render() {
		$user = wp_get_current_user();

		if ( ! $user->exists() || user_can( $user, 'edit_posts' ) ) {
			return;
		}

		$blocked = $this->get_blocked_reason( $user );

		if ( isset( $_GET[ self::FAILED ] ) ) {
			$blocked = __( 'Something went wrong while cancelling your subscription, so your account wasn\'t deleted. I\'ve been notified.', 'daan-mods' );
		}
		?>
		<div id="daan-delete-account" class="daan-delete-account">
			<h3><?php esc_html_e( 'Delete account', 'daan-mods' ); ?></h3>
			<?php if ( $blocked ) : ?>
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
				<p><?php esc_html_e( 'Deleting your account removes your personal data from Daan.dev. This can\'t be undone.', 'daan-mods' ); ?></p>
				<ul>
					<?php foreach ( $this->get_consequences( $user ) as $consequence ) : ?>
						<li><?php echo esc_html( $consequence ); ?></li>
					<?php endforeach; ?>
				</ul>
				<form method="post" action="<?php echo esc_url( edd_get_current_page_url() ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<p>
						<label>
							<input type="checkbox" name="daan_delete_account_confirm" value="1" required>
							<?php esc_html_e( 'I understand that my account will be deleted, and that this can\'t be undone.', 'daan-mods' ); ?>
						</label>
					</p>
					<p>
						<button type="submit" class="button daan-delete-account-button"><?php esc_html_e( 'Delete my account', 'daan-mods' ); ?></button>
					</p>
				</form>
				<?php $this->print_button_assets(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A red button, which is only enabled once the checkbox is checked. Without JavaScript, the checkbox's required
	 * attribute does the same.
	 *
	 * @return void
	 */
	private function print_button_assets() {
		?>
		<style>
			/* Tailwind's red-600 and red-700: white text on them meets WCAG AA. */
			.daan-delete-account .daan-delete-account-button {
				background: oklch(57.7% .245 27.325);
				border-color: oklch(57.7% .245 27.325);
				color: #fff;
			}

			.daan-delete-account .daan-delete-account-button:hover,
			.daan-delete-account .daan-delete-account-button:focus {
				background: oklch(50.5% .213 27.518);
				border-color: oklch(50.5% .213 27.518);
				color: #fff;
			}

			.daan-delete-account .daan-delete-account-button:disabled {
				background: #e5e7eb;
				border-color: #e5e7eb;
				color: #6b7280;
				cursor: not-allowed;
			}
		</style>
		<script>
			( function () {
				const form = document.querySelector( '#daan-delete-account form' );
				const checkbox = form.querySelector( 'input[name="daan_delete_account_confirm"]' );
				const button = form.querySelector( '.daan-delete-account-button' );
				const toggle = () => button.disabled = ! checkbox.checked;

				checkbox.addEventListener( 'change', toggle );
				toggle();
			} )();
		</script>
		<?php
	}

	/**
	 * After deleting their account, the visitor is logged out and returns to the Account page.
	 *
	 * @return void
	 */
	public function render_deleted() {
		if ( ! isset( $_GET[ self::DELETED ] ) ) {
			return;
		}
		?>
		<div class="edd-alert edd-alert-success">
			<p><?php esc_html_e( 'Your account has been deleted.', 'daan-mods' ); ?></p>
		</div>
		<?php
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
	 * What deleting this account does.
	 *
	 * @param \WP_User $user
	 *
	 * @return string[]
	 */
	private function get_consequences( $user ) {
		$consequences = [
			__( 'Your account, name, email address and billing address will be deleted.', 'daan-mods' ),
			__( 'Your orders will be anonymized. What was bought, when, for how much and in which country is kept, because the tax administration requires it. For the same reason, invoices are kept for seven years.', 'daan-mods' ),
		];

		$subscriptions = count( $this->get_active_subscriptions( $user ) );

		if ( $subscriptions ) {
			$consequences[] = sprintf(
				/* translators: %d: number of subscriptions. */
				_n(
					'Your active subscription will be cancelled. You won\'t be charged again.',
					'Your %d active subscriptions will be cancelled. You won\'t be charged again.',
					$subscriptions,
					'daan-mods'
				),
				$subscriptions
			);
		}

		if ( $this->get_licenses( $user ) ) {
			$consequences[] = __( 'Your license keys will keep working until they expire, and can still be renewed with the license key at checkout. You won\'t get renewal reminders anymore, so save your license keys first.', 'daan-mods' );
		}

		$consequences[] = __( 'You\'re removed from any newsletters you might be subscribed to.', 'daan-mods' );

		return $consequences;
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

		if ( user_can( $user, 'edit_posts' ) || $this->get_blocked_reason( $user ) || empty( $_POST[ 'daan_delete_account_confirm' ] ) ) {
			wp_safe_redirect( edd_get_current_page_url() );
			exit;
		}

		$customer      = $this->get_customer( $user );
		$emails        = array_unique( array_filter( [ $user->user_email, $customer->email ?? '' ] ) );
		$subscriptions = $this->cancel_subscriptions( $user );

		// Don't delete the account of someone who may still be charged.
		if ( $subscriptions[ 'failed' ] ) {
			$this->notify_failed( $user, $emails, $subscriptions[ 'failed' ] );

			wp_safe_redirect( add_query_arg( self::FAILED, 1, edd_get_current_page_url() ) . '#daan-delete-account' );
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

		$subject = $failed ? '[%s] Account deleted, but personal data may remain' : '[%s] Account deleted';

		wp_mail( get_option( 'admin_email' ), sprintf( $subject, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), implode( "\n", $lines ) );
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

		wp_mail( get_option( 'admin_email' ), sprintf( '[%s] Account not deleted', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), implode( "\n", $lines ) );
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
