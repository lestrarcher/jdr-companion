export type RestType = 'short-rest' | 'long-rest';

export type RestRequestStatus =
  | 'pending'
  | 'approved'
  | 'rejected';

export interface RestRequest {
  id: string;
  campaignId: string;
  sessionId: string;
  characterId: string;
  characterName: string;
  type: RestType;
  status: RestRequestStatus;
  requestedAt: string;
  resolvedAt?: string;
}
