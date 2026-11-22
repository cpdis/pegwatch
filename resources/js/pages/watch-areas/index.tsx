import InputError from '@/components/input-error';
import PublicLayout from '@/components/public-layout';
import TenementMap from '@/components/tenement-map';
import { Button } from '@/components/ui/button';
import { Head, router, useForm } from '@inertiajs/react';

interface Area {
    id: number;
    name: string;
    latitude: number;
    longitude: number;
    radius_km: number;
    event_types: string[];
    tenements: number;
}

const TYPE_LABELS: Record<string, string> = {
    death: 'Tenement recorded dead, with the share of ground left uncovered',
    release_scheduled: 'Part of a licence scheduled for compulsory release',
    released: 'Release time reached',
};

export default function WatchAreas({
    areas,
    limit,
    eventTypes,
    maxRadius,
}: {
    areas: Area[];
    limit: number;
    eventTypes: string[];
    maxRadius: number;
}) {
    const form = useForm({ name: '', latitude: '' as number | '', longitude: '' as number | '', radius_km: 10, event_types: eventTypes });
    const canAdd = areas.length < limit;
    const centre: [number, number] | null =
        form.data.longitude !== '' && form.data.latitude !== '' ? [Number(form.data.longitude), Number(form.data.latitude)] : null;
    const shown = areas[0];

    return (
        <PublicLayout>
            <Head title="My watch area" />
            <h1 className="text-2xl font-semibold">My watch area</h1>
            <p className="text-muted-foreground mt-1 text-sm">
                We email you after the nightly update when something changes inside your area. The free plan has one area.
            </p>

            <div className="mt-4 grid gap-6 md:grid-cols-5">
                <div className="space-y-4 md:col-span-2">
                    {areas.map((a) => (
                        <div key={a.id} className="border-border rounded-md border p-3 text-sm">
                            <div className="font-medium">{a.name}</div>
                            <div className="text-muted-foreground">
                                {a.radius_km} km around {a.latitude.toFixed(4)}, {a.longitude.toFixed(4)} · {a.tenements} tenements inside today
                            </div>
                            <ul className="mt-1 list-disc pl-5">
                                {a.event_types.map((t) => (
                                    <li key={t}>{TYPE_LABELS[t] ?? t}</li>
                                ))}
                            </ul>
                            <Button variant="outline" size="sm" className="mt-2" onClick={() => router.delete(`/watch-areas/${a.id}`)}>
                                Delete
                            </Button>
                        </div>
                    ))}

                    {canAdd && (
                        <form
                            className="space-y-3 text-sm"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post('/watch-areas');
                            }}
                        >
                            <p>Click the map to set the centre.</p>
                            <label className="block">
                                Name
                                <input
                                    className="border-input mt-1 block w-full rounded-md border px-2 py-1"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder="e.g. Kalgoorlie east"
                                />
                                <InputError message={form.errors.name} />
                            </label>
                            <label className="block">
                                Radius: {form.data.radius_km} km
                                <input
                                    type="range"
                                    min={1}
                                    max={maxRadius}
                                    value={form.data.radius_km}
                                    onChange={(e) => form.setData('radius_km', Number(e.target.value))}
                                    className="block w-full"
                                />
                            </label>
                            <fieldset>
                                <legend>Alert me about</legend>
                                {eventTypes.map((t) => (
                                    <label key={t} className="flex items-start gap-2">
                                        <input
                                            type="checkbox"
                                            className="mt-1"
                                            checked={form.data.event_types.includes(t)}
                                            onChange={(e) =>
                                                form.setData(
                                                    'event_types',
                                                    e.target.checked ? [...form.data.event_types, t] : form.data.event_types.filter((x) => x !== t),
                                                )
                                            }
                                        />
                                        {TYPE_LABELS[t] ?? t}
                                    </label>
                                ))}
                                <InputError message={form.errors.event_types} />
                            </fieldset>
                            <InputError message={form.errors.latitude || form.errors.longitude} />
                            <Button type="submit" disabled={form.processing || !centre}>
                                Save watch area
                            </Button>
                        </form>
                    )}
                </div>
                <div className="md:col-span-3">
                    <TenementMap
                        className="h-[480px] w-full rounded-md"
                        circle={
                            centre
                                ? { center: centre, radiusKm: form.data.radius_km }
                                : shown
                                  ? { center: [shown.longitude, shown.latitude], radiusKm: shown.radius_km }
                                  : null
                        }
                        bounds={shown ? [shown.longitude - 0.5, shown.latitude - 0.5, shown.longitude + 0.5, shown.latitude + 0.5] : null}
                        onClickMap={
                            canAdd
                                ? ([lng, lat]) => {
                                      form.setData((d) => ({ ...d, longitude: Number(lng.toFixed(5)), latitude: Number(lat.toFixed(5)) }));
                                  }
                                : undefined
                        }
                    />
                </div>
            </div>
        </PublicLayout>
    );
}
