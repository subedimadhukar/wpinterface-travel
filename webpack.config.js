/**
 * Extends the @wordpress/scripts config with our entry points.
 * Output: assets/build/{entry}.js, {entry}.asset.php, {entry}.css
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'trip-editor': path.resolve(
			__dirname,
			'assets/src/trip-editor/index.js'
		),
		settings: path.resolve( __dirname, 'assets/src/settings/index.js' ),
		coupons: path.resolve( __dirname, 'assets/src/coupons/index.js' ),
		booking: path.resolve( __dirname, 'assets/src/booking/index.js' ),
		account: path.resolve( __dirname, 'assets/src/account/index.js' ),
		emails: path.resolve( __dirname, 'assets/src/emails/index.js' ),
		enquiries: path.resolve( __dirname, 'assets/src/enquiries/index.js' ),
		setup: path.resolve( __dirname, 'assets/src/setup/index.js' ),
		dashboard: path.resolve( __dirname, 'assets/src/dashboard/index.js' ),
		bookings: path.resolve( __dirname, 'assets/src/bookings/index.js' ),
		blocks: path.resolve( __dirname, 'assets/src/blocks/index.js' ),
		frontend: path.resolve( __dirname, 'assets/src/frontend/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/build' ),
	},
};
