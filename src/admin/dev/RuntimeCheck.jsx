/**
 * Developer check of the client layer (#/tata/runtime, WP_DEBUG only): the
 * screen comes from the client bundle, renders free components, and reads
 * Pro's data — so load order and all three layers are proven at once. Not
 * translated: developers only.
 */
import { Badge, EmptyState, Panel } from '@rtbp/ui';

export default function RuntimeCheck() {
	const pro = window.rtbpPro;
	const features = pro?.features || [];

	return (
		<Panel
			title="Layers"
			description="Free runtime, Pro data and this add-on, in one screen"
		>
			<ul className="m-0 list-none space-y-2 p-0 text-sm">
				<li>
					Free runtime:{ ' ' }
					<strong>window.rtbp v{ window.rtbp?.version }</strong>
				</li>
				<li>
					Pro:{ ' ' }
					<strong>
						{ pro ? `v${ pro.version }` : 'not loaded' }
					</strong>
				</li>
			</ul>
			{ features.length ? (
				<div className="mt-4 flex flex-wrap gap-2">
					{ features.map( ( feature ) => (
						<Badge
							key={ feature.key }
							variant={
								feature.state === 'active'
									? 'default'
									: 'outline'
							}
							title={ feature.state }
						>
							{ feature.label }
						</Badge>
					) ) }
				</div>
			) : (
				<EmptyState
					title="No Pro features reported"
					description="Pro is inactive or its bundle is not built."
				/>
			) }
		</Panel>
	);
}
