@extends('layouts.admin', ['title' => 'Subscriptions'])

@section('admin')
    <h1>Subscriptions</h1>
    <p class="meta">Monthly and yearly subscriptions, kept in sync by the Stripe webhook. Set the plan and which packages it includes under <a href="{{ url('/admin/catalog') }}">Pricing</a>.</p>
    <nav class="tabs" aria-label="Subscription status">
        <a href="{{ url('/admin/subscriptions') }}" @if ($status === '') aria-current="page" @endif>All <span class="meta">{{ $counts->sum() }}</span></a>
        @foreach ($statuses as $value)
            @continue(! isset($counts[$value]))
            <a href="{{ url('/admin/subscriptions?status='.$value) }}" @if ($status === $value) aria-current="page" @endif>{{ ucfirst(str_replace('_', ' ', $value)) }} <span class="meta">{{ $counts[$value] }}</span></a>
        @endforeach
    </nav>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Started</th><th>Customer</th><th>Amount</th><th>Status</th><th>Renews or ends</th><th>Stripe</th></tr></thead>
            <tbody>
            @forelse ($subscriptions as $subscription)
                <tr>
                    <td>{{ $subscription->created_at->format('Y-m-d H:i') }}</td>
                    <td>{{ $subscription->user?->email }}</td>
                    <td>{{ $subscription->priceLabel() }}</td>
                    <td>
                        <span class="status status-{{ $subscription->status }}">{{ ucfirst(str_replace('_', ' ', $subscription->status)) }}</span>
                        @if ($subscription->cancel_at_period_end && $subscription->isLive())<br><span class="meta">Cancels at period end</span>@endif
                    </td>
                    <td>
                        @if ($subscription->ended_at)
                            Ended {{ $subscription->ended_at->format('Y-m-d') }}
                        @elseif ($subscription->current_period_end)
                            {{ $subscription->cancel_at_period_end ? 'Ends' : 'Renews' }} {{ $subscription->current_period_end->format('Y-m-d') }}
                        @else
                            —
                        @endif
                    </td>
                    <td>@if ($subscription->stripe_subscription)<code>{{ $subscription->stripe_subscription }}</code>@else<span class="meta">—</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6" class="meta">No subscriptions yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $subscriptions->links() }}
@endsection
