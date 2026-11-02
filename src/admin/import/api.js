/**
 * The legacy import screen's REST calls (M18 T2b). Administrators only; the
 * server enforces it.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@rtbp/lib';

export const IMPORT_KEY = [ 'tata', 'import' ];

/**
 * Source prefix, legacy tables found, importers, the last run.
 *
 * @return {Object} Query.
 */
export const useImportStatus = () =>
	useQuery( {
		queryKey: IMPORT_KEY,
		queryFn: () => api.get( 'tata/import' ).then( ( r ) => r.data ),
	} );

/**
 * A dry run (`dryRun`) or the import itself.
 *
 * @param {boolean} dryRun Dry run.
 * @return {Object} Mutation; `mutateAsync( { source_prefix, as_of } )` resolves to `{ data, message }`.
 */
export function useRunImport( dryRun ) {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( input ) =>
			api.post(
				dryRun ? 'tata/import/dry-run' : 'tata/import/run',
				input
			),
		onSuccess: ( { data } ) => {
			client.setQueryData( IMPORT_KEY, ( status ) =>
				status
					? {
							...status,
							last: data,
							source_prefix: data.params.source_prefix,
					  }
					: status
			);
			// The table check follows the (possibly new) source prefix; a real
			// import also changes every other screen's data.
			client.invalidateQueries(
				dryRun ? { queryKey: IMPORT_KEY } : undefined
			);
		},
	} );
}
