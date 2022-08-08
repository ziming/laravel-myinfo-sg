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

    /**
     * @return mixed[]
     */
    public function vehicles(): array
    {
        return Arr::get($this->myinfoData, "vehicles", []);
    }

    public function vehiclesRowMake(int $index): string
    {
        return Arr::get($this->vehicles(), "{$index}.make.value");
    }

    public function vehiclesRowModel(int $index): string
    {
        return Arr::get($this->vehicles(), "{$index}.model.value");
    }

    public function vehiclesRowEffectiveOwnership(int $index): string
    {
        return Arr::get($this->vehicles(), "{$index}.effectiveownership.value");
    }

    /**
     * @return mixed[]
     */
    public function cpfContributions(): array
    {
        return Arr::get($this->myinfoData, "cpfcontributions.history", []);
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

    /**
     * @return mixed[]
     */
    public function noticeOfAssessmentsDetailed(): array
    {
        return Arr::get($this->myinfoData, "noahistory.noas", []);
    }

    public function noticeOfAssessmentsDetailedRowYear(int $index): int
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.yearofassessment.value");
    }

    public function noticeOfAssessmentsDetailedRowAmount(int $index): float
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.amount.value");
    }

    public function noticeOfAssessmentsDetailedRowEmployment(int $index): float
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.employment.value");
    }

    public function noticeOfAssessmentsDetailedRowTrade(int $index): float
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.trade.value");
    }

    public function noticeOfAssessmentsDetailedRowRent(int $index): float
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.rent.value");
    }

    public function noticeOfAssessmentsDetailedRowInterest(int $index): float
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.interest.value");
    }

    public function noticeOfAssessmentsDetailedRowTaxClearance(int $index): string
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.taxclearance.value");
    }

    public function noticeOfAssessmentsDetailedRowCategory(int $index): string
    {
        return Arr::get($this->noticeOfAssessmentsDetailed(), "{$index}.category.value");
    }

    /**
     * @return mixed[]
     */
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

    /**
     * @return mixed[]
     */
    public function hdbOwnerships(): array
    {
        return Arr::get($this->myinfoData, 'hdbownership', []);
    }

    public function hdbOwnershipsRowNoOfOwners(int $index): int
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

    /**
     * @return mixed[]
     */
    public function cpfHousingWithdrawals(): array
    {
        return Arr::get($this->myinfoData, "cpfhousingwithdrawal.withdrawaldetails", []);
    }

    public function cpfHousingWithdrawalsRowAddressType(): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "address.type") ?: null;
    }

    public function cpfHousingWithdrawalsRowAddressBlock(): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "address.block.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowAddressBuilding(): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "address.building.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowAddressFloor(): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "address.floor.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowAddressUnit(): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "address.unit.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowAddressStreet(): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "address.street.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowAddressPostal(): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "address.postal.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowAddressCountry(): ?string
    {
        return Arr::get($this->cpfHousingWithdrawals(), "address.country.desc") ?: null;
    }

    public function cpfHousingWithdrawalsRowAccruedInterestAmt(): ?float
    {
        return Arr::get($this->cpfHousingWithdrawals(), "accruedinterestamt.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowMonthlyInstalmentAmt(): ?float
    {
        return Arr::get($this->cpfHousingWithdrawals(), "monthlyinstalmentamt.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowPrincipalWithdrawalAmt(): ?float
    {
        return Arr::get($this->cpfHousingWithdrawals(), "principalwithdrawalamt.value") ?: null;
    }

    public function cpfHousingWithdrawalsRowTotalAmountOfCpfAllowedForProperty(): ?float
    {
        return Arr::get($this->cpfHousingWithdrawals(), "totalamountofcpfallowedforproperty.value") ?: null;
    }

    /**
     * @return mixed[]
     */
    public function childrenBirthRecords(): array
    {
        return Arr::get($this->myinfoData, "childrenbirthrecords", []);
    }

    public function childrenBirthRecordsRowLifeStatus(int $index, string $key = 'desc')
    {
        return Arr::get($this->childrenBirthRecords(), "{$index}.lifestatus.{$key}");
    }
}
