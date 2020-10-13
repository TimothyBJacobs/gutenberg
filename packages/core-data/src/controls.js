/**
 * External dependencies
 */
import { chunk } from 'lodash';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

export function addToBatch( batchId, request ) {
	return {
		type: 'ADD_TO_BATCH',
		batchId,
		request,
	};
}

export function commitBatch( batchId ) {
	return {
		type: 'COMMIT_BATCH',
		batchId,
	};
}

const BATCHES = {};
const BATCH_SIZE = 20;

export const controls = {
	ADD_TO_BATCH( { batchId, request } ) {
		BATCHES[ batchId ] = BATCHES[ batchId ] || {
			requests: [],
			state: 'waiting',
		};

		if ( BATCHES[ batchId ].state !== 'waiting' ) {
			throw new Error( 'Trying to add to an in-progress batch.' );
		}

		return new Promise( ( resolve, reject ) => {
			BATCHES[ batchId ].requests.push( { resolve, reject, request } );
		} );
	},
	async COMMIT_BATCH( { batchId } ) {
		if ( ! BATCHES[ batchId ] ) {
			return null;
		}

		BATCHES[ batchId ].state = 'in-flight';

		// Maybe we could reuse raw options instead of mapping like that.
		const requests = BATCHES[ batchId ].requests.map( ( { request } ) => ( {
			path: request.path,
			body: request.data,
			headers: request.headers,
		} ) );
		const chunks = chunk( requests, BATCH_SIZE );
		const allResponses = [];

		for ( let i = 0; i < chunks.length; i++ ) {
			const chunkResponse = await apiFetch( {
				path: '/__experimental/batch',
				method: 'POST',
				data: {
					validation: 'require-all-validate',
					requests: chunks[ i ],
				},
			} ).then(
				( ( chunkNumber ) => ( batchResponse ) => {
					for (
						let j = chunkNumber * BATCH_SIZE;
						j < batchResponse.responses.length;
						j++
					) {
						const { request, resolve, reject } = BATCHES[
							batchId
						].requests[ j ];
						const data = batchResponse.responses[ j ];

						let response, ok;

						if ( request.parse === false ) {
							response = new window.Response(
								JSON.stringify( data.body ),
								{
									status: data.status,
									headers: data.headers,
								}
							);
							ok = response.ok;
						} else {
							response = data.body;
							ok = data.status < 400;
						}

						if ( ok ) {
							resolve( response );
						} else {
							reject( response );
						}
					}

					return batchResponse;
				} )( i )
			);

			allResponses.push( ...chunkResponse.responses );
		}

		BATCHES[ batchId ].state = 'completed';

		return allResponses;
	},
};
