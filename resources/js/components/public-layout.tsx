import AppLogoIcon from '@/components/app-logo-icon';
import TenementSearch from '@/components/tenement-search';
import { awst } from '@/lib/format';
import { type SharedData } from '@/types';
import { type Run } from '@/types/pegwatch';
import { Link, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

export default function PublicLayout({ children, run, fullBleed = false }: { children: ReactNode; run?: Run | null; fullBleed?: boolean }) {
    const { auth } = usePage<SharedData>().props;
    return (
        <div className="bg-background text-foreground flex min-h-screen flex-col">
            <header className="border-border flex flex-wrap items-center gap-3 border-b px-4 py-2">
                <Link href="/" className="flex items-center gap-2 font-semibold">
                    <AppLogoIcon className="size-6 fill-current" />
                    Pegwatch
                </Link>
                <div className="order-last w-full sm:order-none sm:w-80">
                    <TenementSearch />
                </div>
                <nav className="ml-auto flex items-center gap-4 text-sm">
                    <Link href="/activity" className="hover:underline">
                        Activity
                    </Link>
                    {auth.user ? (
                        <Link href="/watch-areas" className="hover:underline">
                            My watch area
                        </Link>
                    ) : (
                        <>
                            <Link href={route('login')} className="hover:underline">
                                Log in
                            </Link>
                            <Link href={route('register')} className="bg-primary text-primary-foreground rounded-md px-3 py-1.5">
                                Free alerts
                            </Link>
                        </>
                    )}
                </nav>
            </header>
            <main className={fullBleed ? 'flex flex-1 flex-col' : 'mx-auto w-full max-w-5xl flex-1 px-4 py-6'}>{children}</main>
            <footer className="border-border text-muted-foreground border-t px-4 py-3 text-xs">
                {run?.tenements_extract && (
                    <span>
                        Register as of the department's extract of {awst(run.tenements_extract)} AWST
                        {run.stale ? ' (not refreshed since the previous night)' : ''}.{' '}
                    </span>
                )}
                A screening aid, not advice that ground can be applied for: confirm in{' '}
                <a className="underline" href="https://dmpe.wa.gov.au/geoscience/tengraph" target="_blank" rel="noreferrer">
                    TENGRAPH
                </a>{' '}
                and with the Mining Registrar. Data © State of Western Australia (Department of Mines, Petroleum and Exploration), CC BY 4.0, changes
                made.
            </footer>
        </div>
    );
}
