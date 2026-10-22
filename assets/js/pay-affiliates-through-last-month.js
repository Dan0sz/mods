/**
 * Adds "Quick: all unpaid referrals through last month" to the Date range of AffiliateWP's Pay Affiliates screen: no
 * start date, and the last day of the previous month as end date.
 *
 * @see \Daan\Mods\PayAffiliatesThroughLastMonth
 */
( function () {
	const PRESET = 'through-last-month';
	const config = window.daanPayAffiliatesThroughLastMonth || {};
	const select = document.getElementById( 'date-preset' );

	if ( ! select || select.querySelector( 'option[value="' + PRESET + '"]' ) ) {
		return;
	}

	// Right below AffiliateWP's own quick option ("all unpaid referrals through today").
	const option = new Option( config.label, PRESET );
	const quick = select.querySelector( 'option[value="all-unpaid"]' );

	select.insertBefore( option, quick ? quick.nextSibling : select.firstChild );

	// Alpine is deferred, so this runs after AffiliateWP registered its store, but before the form uses it.
	document.addEventListener( 'alpine:init', () => {
		const store = window.Alpine.store( 'payAffiliatesForm' );

		if ( ! store || typeof store.applyDatePreset !== 'function' ) {
			return;
		}

		const applyDatePreset = store.applyDatePreset;

		store.applyDatePreset = function ( preset ) {
			if ( preset !== PRESET ) {
				return applyDatePreset.call( this, preset );
			}

			const today = new Date();
			const lastDay = new Date( today.getFullYear(), today.getMonth(), 0 );
			const month = String( lastDay.getMonth() + 1 ).padStart( 2, '0' );
			const day = String( lastDay.getDate() ).padStart( 2, '0' );

			this.startDate = '';
			this.endDate = `${ lastDay.getFullYear() }-${ month }-${ day }`;
		};
	} );
} )();
