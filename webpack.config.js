/**
 * Webpack configuration.
 *
 * Extends @wordpress/scripts. WordPress packages (react, @wordpress/*) are
 * externalised as usual; the free plugin's runtime is externalised here, the
 * same way as in Pro (ADR-015), so the add-on never bundles its own copy of
 * the components, the API client or React Query.
 */
const path = require( 'path' );
const defaults = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaults,
	entry: {
		admin: path.resolve( __dirname, 'src/admin/index.js' ),
	},
	output: {
		...defaults.output,
		path: path.resolve( __dirname, 'build' ),
	},
	externals: {
		...( defaults.externals || {} ),
		'@rtbp/ui': [ 'rtbp', 'ui' ],
		'@rtbp/lib': [ 'rtbp', 'lib' ],
		'@tanstack/react-query': [ 'rtbp', 'ReactQuery' ],
		// The app's router: a bundled copy would not see its router context.
		'react-router-dom': [ 'rtbp', 'router' ],
	},
	resolve: {
		...defaults.resolve,
		extensions: [
			'.js',
			'.jsx',
			'.json',
			...( defaults.resolve?.extensions || [] ),
		],
	},
};
