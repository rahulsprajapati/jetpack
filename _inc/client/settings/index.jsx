/**
 * External dependencies
 */
import React from 'react';
import { translate as __ } from 'i18n-calypso';

/**
 * Internal dependencies
 */
import Discussion from 'discussion';
import Security from 'security/index.jsx';
import Traffic from 'traffic';
import Writing from 'writing/index.jsx';

export default React.createClass( {
	displayName: 'SearchableSettings',

	render() {
		const commonProps = {
			route: this.props.route,
			searchTerm: this.props.searchTerm
		};

		const render = {
			writing:
				'/search' === this.props.route.path
				|| '/settings' === this.props.route.path
				|| '/writing' === this.props.route.path,
			traffic:
				'/search' === this.props.route.path
				|| '/traffic' === this.props.route.path,
			discussion:
				'/search' === this.props.route.path
				|| '/discussion' === this.props.route.path,
			security:
				'/search' === this.props.route.path
				|| '/security' === this.props.route.path
		};

		return (
			<div className="jp-settings-container">
				<div className="jp-no-results">
					{ false !== commonProps.searchTerm
						? __(
							'No search results found for %(term)s',
							{
								args: {
									term: commonProps.searchTerm
								}
							}
						)
						: __( 'Enter a search term to find settings or close search.' )
					}
				</div>
				{ render.writing &&
					<Writing
						siteAdminUrl={ this.props.siteAdminUrl }
						{ ...commonProps }
					/>
				}
				{ render.traffic &&
					<Traffic
						siteRawUrl={ this.props.siteRawUrl }
						siteAdminUrl={ this.props.siteAdminUrl }
						{ ...commonProps }
					/>
				}
				{ render.discussion &&
					<Discussion
						siteRawUrl={ this.props.siteRawUrl }
						{ ...commonProps }
					/>
				}
				{ render.security &&
					<Security
						siteAdminUrl={ this.props.siteAdminUrl }
						{ ...commonProps }
					/>
				}
			</div>
		);
	}
} );