<?php

namespace Ziming\LaravelMyinfoSg\Utils;

use Illuminate\Support\Arr;

/*
 * @internal
 *
 * This class is for my own use for now, I will not care about making breaking changes.
 * You have been warned.
 */
final class MyinfoValueFetcher
{
    private function __construct(private array $myinfoData)
    {
    }

    public static function make(array $myinfoData): self
    {
        return new self($myinfoData);
    }

    public function isNotEmpty(): bool
    {
        return is_array($this->myinfoData);
    }

    public function uinfin(string $key = 'value'): ?string
    {
        return Arr::get($this->myinfoData, "uinfin.{$key}") ?: null;
    }

    public function name(string $key = 'value'): ?string
    {
        return Arr::get($this->myinfoData, "name.{$key}") ?: null;
    }

    public function sex(string $key = 'desc'): ?string
    {
        return Arr::get($this->myinfoData, "sex.{$key}") ?: null;
    }

    public function race(string $key = 'desc'): ?string
    {
        return Arr::get($this->myinfoData, "race.{$key}") ?: null;
    }

    public function dateOfBirth(string $key = 'value'): ?string
    {
        return Arr::get($this->myinfoData, "dob.{$key}") ?: null;
    }

    public function residentialStatus(string $key = 'desc'): ?string
    {
        return Arr::get($this->myinfoData, "residentialstatus.{$key}") ?: null;
    }

    public function nationality(string $key = 'desc'): ?string
    {
        return Arr::get($this->myinfoData, "nationality.{$key}") ?: null;
    }

    public function birthCountry(string $key = 'desc'): ?string
    {
        return Arr::get($this->myinfoData, "birthcountry.{$key}") ?: null;
    }

    public function email(string $key = 'value'): ?string
    {
        return Arr::get($this->myinfoData, "email.{$key}") ?: null;
    }

    public function mobilePhone(string $key = 'value'): ?string
    {
        return Arr::get($this->myinfoData, "mobileno.nbr.{$key}") ?: null;
    }

    public function mobilePhoneFull(): ?string
    {
        $prefix = Arr::get($this->myinfoData, 'mobileno.prefix.value');
        $areaCode = Arr::get($this->myinfoData, 'mobileno.areacode.value');
        $nbr = Arr::get($this->myinfoData, 'mobileno.nbr.value');

        return "{$prefix}{$areaCode}{$nbr}" ?: null;
    }

    public function maritalStatus(string $key = 'desc'): ?string
    {
        return Arr::get($this->myinfoData, "marital.{$key}") ?: null;
    }

    public function passType(string $key = 'desc'): ?string
    {
        return Arr::get($this->myinfoData, "passtype.{$key}") ?: null;
    }

    public function passStatus(string $key = 'value'): ?string
    {
        return Arr::get($this->myinfoData, "passstatus.{$key}") ?: null;
    }

    public function passExpiryDate(string $key = 'value'): ?string
    {
        return Arr::get($this->myinfoData, "passexpirydate.{$key}") ?: null;
    }

    public function regAdd(string $key): ?string
    {
        return Arr::get($this->myinfoData, "regadd.{$key}") ?: null;
    }

    public function regAddFloor(): ?string
    {
        return $this->regAdd("floor.value") ?: null;
    }

    public function regAddUnit(): ?string
    {
        return $this->regAdd("unit.value") ?: null;
    }

    public function regAddCountry(string $key = 'desc'): ?string
    {
        return $this->regAdd("country.{$key}") ?: null;
    }

    public function regAddBlock(): ?string
    {
        return $this->regAdd("block.value") ?: null;
    }

    public function regAddBuilding(): ?string
    {
        return $this->regAdd("building.value") ?: null;
    }

    public function regAddStreet(): ?string
    {
        return $this->regAdd("street.value") ?: null;
    }

    public function regAddPostal(): ?string
    {
        return $this->regAdd("postal.value") ?: null;
    }

    public function regAddLine1(): ?string
    {
        return $this->regAdd("line1.value") ?: null;
    }

    public function regAddLine2(): ?string
    {
        return $this->regAdd("line2.value") ?: null;
    }

    /**
     * Private Residential Property
     */
    public function ownerPrivate($key = 'value'): bool
    {
        return (bool) Arr::get($this->myinfoData, "ownerprivate.{$key}");
    }

    public function employment(string $key = 'value'): ?string
    {
        return Arr::get($this->myinfoData, "employment.{$key}") ?: null;
    }

    public function occupation(string $key = 'value'): ?string
    {
        return Arr::get($this->myinfoData, "occupation.{$key}") ?: null;
    }

    public function drivingDemeritPoints(): ?int
    {
        $demeritPoints = Arr::get($this->myinfoData, "drivinglicence.totaldemeritpoints.value");

        return ($demeritPoints === '' || $demeritPoints === null) ? null : $demeritPoints;
    }

    public function vehicles(): array
    {
        return Arr::get($this->myinfoData, "vehicles", []);
    }

    public function vehiclesRowMake(int $index): ?string
    {
        return Arr::get($this->vehicles(), "{$index}.make.value");
    }

    public function vehiclesRowModel(int $index): ?string
    {
        return Arr::get($this->vehicles(), "{$index}.model.value");
    }

    public function vehiclesRowEffectiveOwnership(int $index): ?string
    {
        return Arr::get($this->vehicles(), "{$index}.effectiveownership.value");
    }

    public function cpfContributions(): array
    {
        return Arr::get($this->myinfoData, "cpfcontributions.history", []);
    }

    public function cpfContributionsRow(int $index): array
    {
        return Arr::get($this->myinfoData, "cpfcontributions.history.{$index}");
    }

    public function cpfContributionsRowMonth(int $index): string
    {
        return Arr::get($this->cpfContributions(), "{$index}.month.value");
    }

    public function cpfContributionsRowDate(int $index): string
    {
        return Arr::get($this->cpfContributions(), "{$index}.date.value");
    }

    public function cpfContributionsRowAmount(int $index): float
    {
        return Arr::get($this->cpfContributions(), "{$index}.amount.value");
    }

    public function cpfContributionsRowEmployer(int $index): string
    {
        return Arr::get($this->cpfContributions(), "{$index}.employer.value");
    }

    public function cpfContributionsUniqueEmployers(): array
    {
        $cpfContributions = $this->cpfContributions();

        $employers = Arr::map($cpfContributions, function ($cpfRecord) {
            return Arr::get($cpfRecord, 'employer.value');
        });

        return array_unique($employers);
    }

    public function cpfContributionsHighestAmountRow(): array
    {
        $highestAmount = 0;
        $highestAmountRow = [];

        $cpfContributions = $this->cpfContributions();
        $cpfContributionsCount = count($this->cpfContributions());

        for ($i = 0; $i < $cpfContributionsCount; $i++) {
            if ($cpfContributions[$i]['amount'] > $highestAmount) {
                $highestAmountRow = $cpfContributions[$i];
            }
        }

        return $highestAmountRow;
    }

    /*
     * Return the employer(s) that appear the most times in employer cpf contributions
     */
    public function cpfContributionsModeEmployers(): array
    {
        $cpfContributions = $this->cpfContributions();
        $employersFrequency = [];
        $highestFrequencyCount = 0;

        foreach ($cpfContributions as $cpfContribution) {
            $employerName = $cpfContribution['employer']['value'];

            if (array_key_exists($employerName, $employersFrequency)) {
                $employersFrequency[$employerName]++;

                if ($employersFrequency[$employerName] > $highestFrequencyCount) {
                    $highestFrequencyCount = $employersFrequency[$employerName];
                }

                continue;
            }

            $employersFrequency[$employerName] = 0;
        }

        $modeEmployers = [];

        foreach ($employersFrequency as $employerName => $employerFrequencyCount) {
            if ($employerFrequencyCount === $highestFrequencyCount) {
                $modeEmployers[] = $employerName;
            }
        }

        return $modeEmployers;
    }

    public function noticeOfAssessmentsDetailed(): array
    {
        return Arr::get($this->myinfoData, "noahistory.noas", []);
    }

    public function noticeOfAssessmentsDetailedRowYear(int $index): ?int
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.yearofassessment.value");
    }

    public function noticeOfAssessmentsDetailedRowAmount(int $index): ?float
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.amount.value");
    }

    public function noticeOfAssessmentsDetailedRowEmployment(int $index): ?float
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.employment.value");
    }

    public function noticeOfAssessmentsDetailedRowTrade(int $index): ?float
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.trade.value");
    }

    public function noticeOfAssessmentsDetailedRowRent(int $index): ?float
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.rent.value");
    }

    public function noticeOfAssessmentsDetailedRowInterest(int $index): ?float
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.interest.value");
    }

    public function noticeOfAssessmentsDetailedRowTaxClearance(int $index): ?string
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.taxclearance.value");
    }

    public function noticeOfAssessmentsDetailedRowCategory(int $index): ?string
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.category.value");
    }

    public function noticeOfAssessmentsBasic(): array
    {
        return Arr::get($this->myinfoData, "noahistory-basic.noas", []);
    }

    public function noticeOfAssessmentsBasicRowYear(int $index): int
    {
        return Arr::get($this->noticeOfAssessmentsBasic(), "{$index}.yearofassessment.value");
    }

    public function noticeOfAssessmentsBasicRowAmount(int $index): float
    {
        return Arr::get($this->noticeOfAssessmentsBasic(), "{$index}.amount.value");
    }

    public function noticeOfAssessmentBasicYear(): ?int
    {
        return Arr::get($this->myinfoData, "noa-basic.yearofassessment.value");
    }

    public function noticeOfAssessmentBasicAmount(): ?float
    {
        return Arr::get($this->myinfoData, "noa-basic.amount.value");
    }

    public function housingType(string $key = 'desc'): ?string
    {
        return Arr::get($this->myinfoData, "housingtype.{$key}") ?: null;
    }

    public function hdbType(string $key = 'desc'): ?string
    {
        return Arr::get($this->myinfoData, "hdbtype.{$key}") ?: null;
    }

    public function hdbOwnerships(): array
    {
        return Arr::get($this->myinfoData, 'hdbownership', []);
    }

    public function hdbOwnershipsRowNoOfOwners(int $index): ?int
    {
        return Arr::get($this->hdbOwnerships(), "{$index}.noofowners.value");
    }

    public function hdbOwnershipsRowOutstandingLoanBalance(int $index): float
    {
        return Arr::get($this->hdbOwnerships(), "{$index}.outstandingloanbalance.value");
    }

    public function hdbOwnershipsRowMonthlyLoanInstalment(int $index): float
    {
        return Arr::get($this->hdbOwnerships(), "{$index}.monthlyloaninstalment.value");
    }

    public function cpfHousingWithdrawals(): array
    {
        return Arr::get($this->myinfoData, "cpfhousingwithdrawal.withdrawaldetails", []);
    }

    public function cpfHousingWithdrawalsRowAddressType(int $index): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "{$index}.address.type") ?: null;
    }

    public function cpfHousingWithdrawalsRowAddressBlock(int $index): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "{$index}.address.block.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowAddressBuilding(int $index): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "{$index}.address.building.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowAddressFloor(int $index): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "{$index}.address.floor.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowAddressUnit(int $index): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "{$index}.address.unit.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowAddressStreet(int $index): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "{$index}.address.street.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowAddressPostal(int $index): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "{$index}.address.postal.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowAddressCountry(int $index): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "{$index}.address.country.desc") ?: null;
    }

    public function cpfHousingWithdrawalsRowAccruedInterestAmt(int $index): ?float
    {
        return Arr::get($this->cpfHousingWithdrawals(), "{$index}.accruedinterestamt.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowMonthlyInstalmentAmt(int $index): ?float
    {
        return Arr::get($this->cpfHousingWithdrawals(), "{$index}.monthlyinstalmentamt.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowPrincipalWithdrawalAmt(int $index): ?float
    {
        return Arr::get($this->cpfHousingWithdrawals(), "{$index}.principalwithdrawalamt.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowTotalAmountOfCpfAllowedForProperty(int $index): ?float
    {
        return Arr::get($this->cpfHousingWithdrawals(), "{$index}.totalamountofcpfallowedforproperty.value") ?: null;
    }

    public function childrenBirthRecords(): array
    {
        return Arr::get($this->myinfoData, "childrenbirthrecords", []);
    }

    public function childrenBirthRecordsRowLifeStatus(int $index, string $key = 'desc')
    {
        return Arr::get($this->childrenBirthRecords(), "{$index}.lifestatus.{$key}");
    }
}
