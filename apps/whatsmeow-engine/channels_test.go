package engine_test

import (
	"testing"

	"go.mau.fi/whatsmeow/types"

	engine "github.com/surdu/deal-promoter/apps/whatsmeow-engine"
)

// makeNewsletterMeta is a test helper that builds a *types.NewsletterMetadata
// with a @newsletter JID by default, the given name, and the given role.
func makeNewsletterMeta(user, name string, role types.NewsletterRole) *types.NewsletterMetadata {
	return &types.NewsletterMetadata{
		ID: types.NewJID(user, types.NewsletterServer),
		ThreadMeta: types.NewsletterThreadMetadata{
			Name: types.NewsletterText{Text: name},
		},
		ViewerMeta: &types.NewsletterViewerMetadata{Role: role},
	}
}

// makeNonNewsletterMeta builds metadata whose JID is on a non-newsletter server.
func makeNonNewsletterMeta(user, name string, role types.NewsletterRole) *types.NewsletterMetadata {
	return &types.NewsletterMetadata{
		ID: types.NewJID(user, types.DefaultUserServer),
		ThreadMeta: types.NewsletterThreadMetadata{
			Name: types.NewsletterText{Text: name},
		},
		ViewerMeta: &types.NewsletterViewerMetadata{Role: role},
	}
}

func TestMapNewsletters_OwnerKept(t *testing.T) {
	meta := []*types.NewsletterMetadata{
		makeNewsletterMeta("123456789", "My Channel", types.NewsletterRoleOwner),
	}

	got := engine.MapNewsletters(meta)

	if len(got) != 1 {
		t.Fatalf("len = %d, want 1", len(got))
	}
	ch := got[0]
	if ch.ID != "123456789@newsletter" {
		t.Errorf("ID = %q, want %q", ch.ID, "123456789@newsletter")
	}
	if ch.Name != "My Channel" {
		t.Errorf("Name = %q, want %q", ch.Name, "My Channel")
	}
	if ch.Role != "OWNER" {
		t.Errorf("Role = %q, want %q", ch.Role, "OWNER")
	}
}

func TestMapNewsletters_AdminKept(t *testing.T) {
	meta := []*types.NewsletterMetadata{
		makeNewsletterMeta("987654321", "Admin Channel", types.NewsletterRoleAdmin),
	}

	got := engine.MapNewsletters(meta)

	if len(got) != 1 {
		t.Fatalf("len = %d, want 1", len(got))
	}
	if got[0].Role != "ADMIN" {
		t.Errorf("Role = %q, want %q", got[0].Role, "ADMIN")
	}
}

func TestMapNewsletters_SubscriberDropped(t *testing.T) {
	meta := []*types.NewsletterMetadata{
		makeNewsletterMeta("111", "Sub Channel", types.NewsletterRoleSubscriber),
	}

	got := engine.MapNewsletters(meta)

	if len(got) != 0 {
		t.Errorf("len = %d, want 0 (subscriber must be dropped)", len(got))
	}
}

func TestMapNewsletters_GuestDropped(t *testing.T) {
	meta := []*types.NewsletterMetadata{
		makeNewsletterMeta("222", "Guest Channel", types.NewsletterRoleGuest),
	}

	got := engine.MapNewsletters(meta)

	if len(got) != 0 {
		t.Errorf("len = %d, want 0 (guest must be dropped)", len(got))
	}
}

func TestMapNewsletters_NonNewsletterJIDDropped(t *testing.T) {
	meta := []*types.NewsletterMetadata{
		makeNonNewsletterMeta("333", "Normal User", types.NewsletterRoleOwner),
	}

	got := engine.MapNewsletters(meta)

	if len(got) != 0 {
		t.Errorf("len = %d, want 0 (non-@newsletter JID must be dropped)", len(got))
	}
}

func TestMapNewsletters_NilViewerMetaDropped(t *testing.T) {
	meta := []*types.NewsletterMetadata{
		{
			ID: types.NewJID("444", types.NewsletterServer),
			ThreadMeta: types.NewsletterThreadMetadata{
				Name: types.NewsletterText{Text: "No Viewer Meta"},
			},
			ViewerMeta: nil,
		},
	}

	got := engine.MapNewsletters(meta)

	if len(got) != 0 {
		t.Errorf("len = %d, want 0 (nil ViewerMeta must be dropped)", len(got))
	}
}

func TestMapNewsletters_EmptyInputReturnsEmptyNonNilSlice(t *testing.T) {
	got := engine.MapNewsletters([]*types.NewsletterMetadata{})

	if got == nil {
		t.Fatal("got nil, want non-nil empty slice")
	}
	if len(got) != 0 {
		t.Errorf("len = %d, want 0", len(got))
	}
}

func TestMapNewsletters_MixedInputFiltersCorrectly(t *testing.T) {
	meta := []*types.NewsletterMetadata{
		makeNewsletterMeta("owner1", "Owner Chan", types.NewsletterRoleOwner),
		makeNewsletterMeta("admin1", "Admin Chan", types.NewsletterRoleAdmin),
		makeNewsletterMeta("sub1", "Sub Chan", types.NewsletterRoleSubscriber),
		makeNewsletterMeta("guest1", "Guest Chan", types.NewsletterRoleGuest),
		makeNonNewsletterMeta("nonewsletter", "Non-NL", types.NewsletterRoleOwner),
	}

	got := engine.MapNewsletters(meta)

	if len(got) != 2 {
		t.Fatalf("len = %d, want 2 (only owner + admin)", len(got))
	}
	ids := map[string]bool{got[0].ID: true, got[1].ID: true}
	if !ids["owner1@newsletter"] {
		t.Errorf("expected owner1@newsletter in result")
	}
	if !ids["admin1@newsletter"] {
		t.Errorf("expected admin1@newsletter in result")
	}
}
