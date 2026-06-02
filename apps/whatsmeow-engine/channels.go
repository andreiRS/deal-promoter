package engine

import (
	"context"
	"strings"

	"go.mau.fi/whatsmeow/types"
)

// MapNewsletters maps a slice of whatsmeow NewsletterMetadata to []Channel,
// keeping only entries whose JID server is "newsletter" (i.e. ends in
// @newsletter) and whose viewer role is OWNER or ADMIN. Entries with a nil
// ViewerMeta are dropped. Always returns a non-nil slice.
func MapNewsletters(meta []*types.NewsletterMetadata) []Channel {
	out := make([]Channel, 0, len(meta))
	for _, m := range meta {
		if m.ViewerMeta == nil {
			continue
		}
		role := m.ViewerMeta.Role
		if role != types.NewsletterRoleOwner && role != types.NewsletterRoleAdmin {
			continue
		}
		id := m.ID.String()
		if !strings.HasSuffix(id, "@newsletter") {
			continue
		}
		out = append(out, Channel{
			ID:   id,
			Name: m.ThreadMeta.Name.Text,
			Role: strings.ToUpper(string(role)),
		})
	}
	return out
}

// Channels returns the list of owned (@newsletter, OWNER or ADMIN) channels
// for the connected WhatsApp account. Returns an empty slice (not an error)
// when the account owns no qualifying channels.
func (e *RealEngine) Channels() ([]Channel, error) {
	meta, err := e.client.GetSubscribedNewsletters(context.Background())
	if err != nil {
		return nil, err
	}
	return MapNewsletters(meta), nil
}
